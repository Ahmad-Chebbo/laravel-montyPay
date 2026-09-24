<?php

namespace AhmadChebbo\LaravelMontypay\Events;

/**
 * Base for the events fired when the customer's browser comes back to success/cancel/decline.
 *
 * These are NOT proof of payment. $payment is the return query string plus `verified`
 * (true/false when the return hash could be checked, null when it could not).
 */
abstract class ReturnEvent
{
    public function __construct(public array $payment)
    {
    }

    public function getPayment(): array
    {
        return $this->payment;
    }
}
