<?php

namespace PhilKra\ElasticApmLaravel\Apm;

use PhilKra\Helper\Timer;
use PhilKra\Events\Transaction as BaseTransaction;

/*
 * Eventually this class could be a proxy for a Transaction provided by the
 * Elastic APM package.
 */
class Transaction extends BaseTransaction
{
    protected $timestamp = null;

    public function __construct(string $name, array $contexts, $start = null)
    {
        parent::__construct($name, $contexts, $start);

        if ($start) {
            $this->timestamp = round($start * 1000000);
        }
    }

    public function jsonSerialize(): array
    {
        $transaction = parent::jsonSerialize()['transaction'];
        $transaction['timestamp'] = $this->timestamp ?? $this->getTimestamp();

        return ['transaction' => $transaction];
    }
}
