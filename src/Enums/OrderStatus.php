<?php

namespace AhmadChebbo\LaravelMontypay\Enums;

/**
 * The `order_status` field: the current state of the payment as a whole.
 */
enum OrderStatus: string
{
    case Prepare = 'prepare';
    case Settled = 'settled';
    case Pending = 'pending';
    case ThreeDs = '3ds';
    case Redirect = 'redirect';
    case Decline = 'decline';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case Void = 'void';
    case Chargeback = 'chargeback';

    /** No further callbacks are expected for the payment (a held DMS authorisation is handled separately). */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Settled, self::Decline, self::Refund, self::Reversal, self::Void, self::Chargeback], true);
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isTerminal()));
    }
}
