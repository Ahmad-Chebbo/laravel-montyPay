<?php

namespace AhmadChebbo\LaravelMontypay\Events;

use AhmadChebbo\LaravelMontypay\Data\MontyPayCallback;

/**
 * Fired for every new verified callback, before the typed outcome event.
 * Prefer the typed events (PaymentSettled, PaymentAuthorized, ...) for business logic.
 */
class CallbackReceived
{
    public $payment;

    public function __construct($payment)
    {
        $this->payment = $payment;
    }

    public function getPayment()
    {
        return $this->payment;
    }

    public function callback(): MontyPayCallback
    {
        return MontyPayCallback::fromArray((array) $this->payment);
    }
}
