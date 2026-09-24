<?php

namespace AhmadChebbo\LaravelMontypay\Events;

/**
 * DMS authorisation: funds are held but not captured. Capture or void the payment; do NOT fulfil yet.
 */
class PaymentAuthorized extends CallbackEvent
{
}
