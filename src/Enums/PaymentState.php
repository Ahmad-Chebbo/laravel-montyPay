<?php

namespace AhmadChebbo\LaravelMontypay\Enums;

/**
 * The merchant-facing lifecycle of a payment, derived from callbacks.
 * State only moves forward (see rank()), so a late intermediary callback
 * can never turn a paid payment back into a pending one.
 */
enum PaymentState: string
{
    case Created = 'created';                    // session created, no callback yet
    case Pending = 'pending';                    // 3ds / redirect / prepare / waiting
    case Failed = 'failed';                      // declined; the payer may retry with a new attempt
    case Undefined = 'undefined';                // uncertain outcome: reconcile before fulfilling
    case Authorized = 'authorized';              // DMS hold, awaiting capture or void
    case Paid = 'paid';                          // settled
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Voided = 'voided';
    case Reversed = 'reversed';
    case Chargeback = 'chargeback';

    public function rank(): int
    {
        return match ($this) {
            self::Created => 0,
            self::Pending, self::Failed, self::Undefined => 1,
            self::Authorized => 2,
            self::Paid => 3,
            self::PartiallyRefunded => 4,
            self::Refunded, self::Voided, self::Reversed => 5,
            self::Chargeback => 6,
        };
    }

    /** Whether a payment in this state may be replaced by $next. */
    public function canBecome(self $next): bool
    {
        return $next->rank() >= $this->rank();
    }
}
