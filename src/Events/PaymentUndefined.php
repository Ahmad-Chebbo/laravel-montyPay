<?php

namespace AhmadChebbo\LaravelMontypay\Events;

/**
 * Uncertain outcome due to upstream issues. Do NOT fulfil; log, alert and reconcile.
 */
class PaymentUndefined extends CallbackEvent
{
}
