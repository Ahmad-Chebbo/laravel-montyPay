<?php

namespace AhmadChebbo\LaravelMontypay\Events;

/**
 * Refund succeeded. Check $callback->isPartialRefund(): a partial refund keeps order_status=settled.
 */
class PaymentRefunded extends CallbackEvent
{
}
