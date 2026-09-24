<?php

namespace AhmadChebbo\LaravelMontypay\Events;

/**
 * Any callback with status=fail (see $callback->type for what failed and $callback->reason for why).
 */
class PaymentDeclined extends CallbackEvent
{
}
