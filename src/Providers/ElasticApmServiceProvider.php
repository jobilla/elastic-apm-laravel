<?php

namespace PhilKra\ElasticApmLaravel\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use PhilKra\Agent;
use PhilKra\ElasticApmLaravel\Apm\CompletedSpan;
use PhilKra\ElasticApmLaravel\Apm\SpanCollection;
use PhilKra\ElasticApmLaravel\Contracts\VersionResolver;
use PhilKra\Events\EventBean;
use PhilKra\Helper\Timer;

class ElasticApmServiceProvider extends ServiceProvider
{
    /** @var float */
    private $startTime;
    /** @var string  */
    private $sourceConfigPath = __DIR__ . '/../../config/elastic-apm.php';

    protected $bootedAt = null;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if (class_exists('Illuminate\Foundation\Application', false)) {
            $this->publishes([
                realpath($this->sourceConfigPath) => config_path('elastic-apm.php'),
            ], 'config');
        }

        if (config('elastic-apm.active') === true && config('elastic-apm.spans.querylog.enabled') !== false) {
            $this->listenForQueries();
        }

        $this->app['events']->listen(RouteMatched::class, function ($event) {
            if (!$this->bootedAt) {
                Log::warning('matched route without a recorded boot time');
                return;
            }

            /** @var SpanCollection $collection */
            $collection = $this->app[SpanCollection::class];
            $tmpParent  = new EventBean([]);
            $tmpParent->setTraceId('123');
            $span = new CompletedSpan('Middleware execution and route matching', $tmpParent, $this->bootedAt);
            $span->setType('httpkernel.route.match');
            $collection->push($span);
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            realpath($this->sourceConfigPath),
            'elastic-apm'
        );

        $this->app->singleton(Agent::class, function ($app) {
            return new Agent(
                array_merge(
                    [
                        'framework' => 'Laravel',
                        'frameworkVersion' => app()->version(),
                    ],
                    [
                        'active' => config('elastic-apm.active'),
                        'httpClient' => config('elastic-apm.httpClient'),
                    ],
                    $this->getAppConfig(),
                    config('elastic-apm.env'),
                    config('elastic-apm.server')
                )
            );
        });

        $this->startTime = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
        $timer = new Timer($this->startTime);

        $collection = new SpanCollection();

        $this->app->instance(Timer::class, $timer);

        $this->app->alias(Agent::class, 'elastic-apm');
        $this->app->instance(SpanCollection::class, $collection);
        $this->app->instance('query-log', $collection);

        $this->app->booted(function ($app) {
            $this->bootedAt = microtime(true);
            /** @var SpanCollection $collection */
            $collection = $app->make('query-log');
            $tmpParent  = new EventBean([]);
            $tmpParent->setTraceId('123');
            $span = new CompletedSpan('Framework booting', $tmpParent, LARAVEL_START);
            $span->setType('framework.booting');
            $collection->push($span);
        });
    }

    /**
     * @return array
     */
    protected function getAppConfig(): array
    {
        $config = config('elastic-apm.app');

        if ($this->app->bound(VersionResolver::class)) {
            $config['appVersion'] = $this->app->make(VersionResolver::class)->getVersion();
        }

        return $config;
    }

    /**
     * @param Collection $stackTrace
     * @return Collection
     */
    protected function stripVendorTraces(Collection $stackTrace): Collection
    {
        return collect($stackTrace)->filter(function ($trace) {
            return !Str::startsWith((Arr::get($trace, 'file')), [
                base_path() . '/vendor',
            ]);
        });
    }

    /**
     * @param array $stackTrace
     * @return Collection
     */
    protected function getSourceCode(array $stackTrace): Collection
    {
        if (config('elastic-apm.spans.renderSource', false) === false) {
            return collect([]);
        }

        if (empty(Arr::get($stackTrace, 'file'))) {
            return collect([]);
        }

        $fileLines = file(Arr::get($stackTrace, 'file'));
        return collect($fileLines)->filter(function ($code, $line) use ($stackTrace) {
            //file starts counting from 0, debug_stacktrace from 1
            $stackTraceLine = Arr::get($stackTrace, 'line') - 1;

            $lineStart = $stackTraceLine - 5;
            $lineStop = $stackTraceLine + 5;

            return $line >= $lineStart && $line <= $lineStop;
        })->groupBy(function ($code, $line) use ($stackTrace) {
            if ($line < Arr::get($stackTrace, 'line')) {
                return 'pre_context';
            }

            if ($line == Arr::get($stackTrace, 'line')) {
                return 'context_line';
            }

            if ($line > Arr::get($stackTrace, 'line')) {
                return 'post_context';
            }

            return 'trash';
        });
    }

    protected function listenForQueries()
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            if (config('elastic-apm.spans.querylog.enabled') === 'auto') {
                if ($query->time < config('elastic-apm.spans.querylog.threshold')) {
                    return;
                }
            }

            $stackTrace = $this->stripVendorTraces(
                collect(
                    debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, config('elastic-apm.spans.backtraceDepth', 50))
                )
            );

            $stackTrace = $stackTrace->map(function ($trace) {
                $sourceCode = $this->getSourceCode($trace);

                return [
                    'function' => Arr::get($trace, 'function') . Arr::get($trace, 'type') . Arr::get($trace,
                            'function'),
                    'abs_path' => Arr::get($trace, 'file'),
                    'filename' => basename(Arr::get($trace, 'file')),
                    'lineno' => Arr::get($trace, 'line', 0),
                    'library_frame' => false,
                    'vars' => $vars ?? null,
                    'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                    'context_line' => optional($sourceCode->get('context_line'))->first(),
                    'post_context' => optional($sourceCode->get('post_context'))->toArray(),
                ];
            })->values();

            $tempParent = new EventBean([]);
            $tempParent->setTraceId('123');
            $span = new CompletedSpan('Eloquent Query', $tempParent, null, $query->time);
            $span->setType('db.mysql.query');
            $span->setStacktrace($stackTrace->toArray());
            $span->setContext([
                'db' => [
                    'instance' => $query->connection->getDatabaseName(),
                    'statement' => $query->sql,
                    'type' => 'sql',
                    'user' => $query->connection->getConfig('username'),
                ],
            ]);

            $this->app->make('query-log')->push($span);
        });
    }
}
