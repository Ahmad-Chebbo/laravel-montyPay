<?php

namespace AhmadChebbo\LaravelMontypay\Enums;

/**
 * The `type` field of a callback: which event the callback describes.
 *
 * @see https://docs.montypay.com/docs/guides/checkout/callbacks#event-types
 */
enum CallbackType: string
{
    case Sale = 'sale';
    case Capture = 'capture';
    case Refund = 'refund';
    case Void = 'void';
    case Recurring = 'recurring';
    case Debit = 'debit';
    case Credit = 'credit';
    case Transfer = 'transfer';
    case ThreeDs = '3ds';
    case Redirect = 'redirect';
    case Init = 'init';
    case Chargeback = 'chargeback';
    case Reversal = 'reversal';

    /** Types that, with order_status=settled, mean money moved and the order can be fulfilled. */
    public function settles(): bool
    {
        return in_array($this, [self::Sale, self::Capture, self::Recurring, self::Debit, self::Transfer, self::Credit], true);
    }

    /** Intermediary steps: wait for the next callback. */
    public function isIntermediary(): bool
    {
        return in_array($this, [self::ThreeDs, self::Redirect, self::Init], true);
    }
}
