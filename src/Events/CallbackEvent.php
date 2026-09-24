<?php

namespace AhmadChebbo\LaravelMontypay\Events;

use AhmadChebbo\LaravelMontypay\Data\MontyPayCallback;

/**
 * Base for the outcome events fired from a verified callback (or from
 * `montypay:reconcile`, in which case $callback->reconciled is true).
 */
abstract class CallbackEvent
{
    public function __construct(public readonly MontyPayCallback $callback)
    {
    }

    /** The raw callback payload. */
    public function getPayment(): array
    {
        return $this->callback->raw;
    }
}
