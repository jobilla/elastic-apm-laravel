<?php

namespace PhilKra\ElasticApmLaravel\Apm;

use PhilKra\ElasticApmLaravel\Exceptions\ZeroTimeSpanException;
use PhilKra\Events\EventBean;
use PhilKra\Events\TraceableEvent;
use PhilKra\Helper\Encoding;
use PhilKra\Helper\Timer;

/**
 * Class CompletedSpan
 *
 * The CompletedSpan is used to represent a span that is recorder
 * retroactively. It accepts a timer that represents the start
 * time, and will immediately stop the timer when created.
 *
 * If no duration is supplied, the duration is assumed to be startTime - now.
 * If no startTime is supplied, the startTime is assumed to be now - duration.
 * If neither startTime nor duration is supplied, an exception is thrown.
 *
 * @package PhilKra\ElasticApmLaravel\Apm
 */
class CompletedSpan extends TraceableEvent implements \JsonSerializable
{

    /**
     * @var string
     */
    private $name;

    /**
     * @var \PhilKra\Helper\Timer
     */
    private $timer;

    /**
     * @var int
     */
    private $duration = 0;

    /**
     * @var string
     */
    private $action = null;

    /**
     * @var string
     */
    private $type = 'request';

    /**
     * @var mixed array|null
     */
    private $context = null;

    /**
     * @var mixed array|null
     */
    private $stacktrace = [];

    /**
     * @var float
     */
    private $startTime;

    /**
     * @param string $name
     * @param EventBean $parent
     * @param float $start
     * @param float|null $duration
     * @throws \PhilKra\Exception\Timer\NotStartedException
     * @throws \PhilKra\Exception\Timer\NotStoppedException
     * @throws ZeroTimeSpanException
     */
    public function __construct(string $name, EventBean $parent, float $start = null, float $duration = null)
    {
        parent::__construct([]);
        $this->name  = trim($name);

        if ($start === null && $duration === null) {
            throw new ZeroTimeSpanException('attempted to create a completed span without specifying start or end');
        }

        if ($start === null) {
            $start = microtime(true) - $duration / 1000;
        }

        $this->timer = new Timer($start);
        $this->startTime = $start;
        $this->stop($duration);
        $this->setParent($parent);
    }


    /**
     * Stop the Timer
     *
     * @param float|null $duration
     *
     * @return void
     * @throws \PhilKra\Exception\Timer\NotStartedException
     * @throws \PhilKra\Exception\Timer\NotStoppedException
     */
    protected function stop(float $duration = null)
    {
        $this->timer->stop();
        $this->duration = $duration ?? round($this->timer->getDurationInMilliseconds(), 3);
    }

    /**
     * Get the Event Name
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * Set the Span's Type
     *
     * @param string $action
     */
    public function setAction(string $action)
    {
        $this->action = trim($action);
    }

    /**
     * Set the Spans' Action
     *
     * @param string $type
     */
    public function setType(string $type)
    {
        $this->type = trim($type);
    }

    /**
     * Provide additional Context to the Span
     *
     * @link https://www.elastic.co/guide/en/apm/server/master/span-api.html
     *
     * @param array $context
     */
    public function setContext(array $context)
    {
        $this->context = $context;
    }

    /**
     * Set a complimentary Stacktrace for the Span
     *
     * @link https://www.elastic.co/guide/en/apm/server/master/span-api.html
     *
     * @param array $stacktrace
     */
    public function setStacktrace(array $stacktrace)
    {
        $this->stacktrace = $stacktrace;
    }

    /**
     * Serialize Span Event
     *
     * @link https://www.elastic.co/guide/en/apm/server/master/span-api.html
     *
     * @return array
     */
    public function jsonSerialize() : array
    {
        return [
            'span' => [
                'id'             => $this->getId(),
                'transaction_id' => $this->getParentId(),
                'trace_id'       => $this->getTraceId(),
                'parent_id'      => $this->getParentId(),
                'type'           => Encoding::keywordField($this->type),
                'action'         => Encoding::keywordField($this->action),
                'context'        => $this->context,
                'duration'       => $this->duration,
                'name'           => Encoding::keywordField($this->getName()),
                'stacktrace'     => $this->stacktrace,
                'sync'           => false,
                'timestamp'      => floor($this->startTime * 1000000),
            ]
        ];
    }
}
