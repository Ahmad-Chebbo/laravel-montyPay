<?php

namespace AhmadChebbo\LaravelMontypay\Models;

use AhmadChebbo\LaravelMontypay\Enums\PaymentState;
use AhmadChebbo\LaravelMontypay\Exceptions\MontyPayException;
use AhmadChebbo\LaravelMontypay\Facades\MontyPay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One payment per order attempt, kept up to date from verified callbacks.
 *
 * Money operations (capture/refund/void) only send the request; the state
 * changes when MontyPay's callback for that operation arrives.
 */
class Payment extends Model
{
    protected $table = 'montypay_payments';

    protected $guarded = [];

    protected $casts = [
        'state' => PaymentState::class,
        'meta' => 'array',
        'recurring_token' => 'encrypted',
        'card_token' => 'encrypted',
        'last_callback_at' => 'datetime',
    ];

    protected $hidden = ['recurring_token', 'card_token'];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPaid(): bool
    {
        return in_array($this->state, [PaymentState::Paid, PaymentState::PartiallyRefunded], true);
    }

    public function isAuthorized(): bool
    {
        return $this->state === PaymentState::Authorized && $this->payment_id !== null;
    }

    public function isRefundable(): bool
    {
        return $this->isPaid() && $this->payment_id !== null;
    }

    /** Capture a held (DMS) authorisation. Defaults to the full amount. */
    public function capture(?string $amount = null): array
    {
        $this->ensure($this->isAuthorized(), 'is not an authorized payment');

        return MontyPay::capture($this->payment_id, $amount ?? $this->amountString());
    }

    /** Refund a settled payment, fully or partially. Defaults to the full amount. */
    public function refund(?string $amount = null): array
    {
        $this->ensure($this->isRefundable(), 'is not a settled payment');

        return MontyPay::refund($this->payment_id, $amount ?? $this->amountString());
    }

    /** Release a held (DMS) authorisation. */
    public function void(): array
    {
        $this->ensure($this->isAuthorized(), 'is not an authorized payment');

        return MontyPay::void($this->payment_id);
    }

    /**
     * Charge this payment's recurring chain again. Currency is inherited from the initial payment.
     *
     * @param  array{number: string, amount: string, description: string}  $order
     */
    public function chargeRecurring(array $order, array $extra = []): array
    {
        $this->ensure(
            $this->recurring_init_trans_id && $this->recurring_token,
            'has no recurring token (create the session with extra.recurring_init = true)'
        );

        return MontyPay::recurring($this->recurring_init_trans_id, $this->recurring_token, $order, $extra + array_filter([
            'schedule_id' => $this->schedule_id,
        ]));
    }

    /** The subscription this payment started is still authorised (a recurring token exists and consent was not cancelled). */
    public function isSubscribed(): bool
    {
        return $this->recurring_token !== null && $this->consent_state !== 'cancelled';
    }

    /** The amount exactly as it was sent to MontyPay, so any currency exponent works. */
    public function amountString(): string
    {
        return (string) $this->amount;
    }

    private function ensure(bool $condition, string $problem): void
    {
        if (! $condition) {
            throw new MontyPayException("Payment #{$this->getKey()} ({$this->order_number}) {$problem}.");
        }
    }
}
