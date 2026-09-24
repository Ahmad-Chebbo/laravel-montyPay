<?php

namespace AhmadChebbo\LaravelMontypay\Events;

/**
 * Money moved (type sale/capture/recurring/debit/transfer/credit, order_status=settled). Safe to fulfil the order.
 */
class PaymentSettled extends CallbackEvent
{
}
