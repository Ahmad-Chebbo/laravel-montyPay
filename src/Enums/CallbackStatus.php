<?php

namespace AhmadChebbo\LaravelMontypay\Enums;

/**
 * The `status` field of a callback: the outcome of the event named by `type`.
 * It does not say the whole payment is final; see OrderStatus.
 */
enum CallbackStatus: string
{
    case Success = 'success';
    case Fail = 'fail';
    case Waiting = 'waiting';
    /** Uncertain due to upstream issues. Never fulfil; reconcile manually. */
    case Undefined = 'undefined';
}
