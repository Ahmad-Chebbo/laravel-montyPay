<?php

namespace AhmadChebbo\LaravelMontypay\Concerns;

use AhmadChebbo\LaravelMontypay\Enums\PaymentState;
use AhmadChebbo\LaravelMontypay\Facades\MontyPay;
use AhmadChebbo\LaravelMontypay\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Add to an Order / Invoice / Subscription model:
 *
 *     use HasMontyPayPayments;
 *
 * then `$order->startMontyPayCheckout(...)`, `$order->isMontyPayPaid()`, `$order->refundMontyPay()`.
 */
trait HasMontyPayPayments
{
    public function montyPayPayments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function latestMontyPayPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->latestOfMany();
    }

    public function isMontyPayPaid(): bool
    {
        return $this->montyPayPaymentIn(PaymentState::Paid, PaymentState::PartiallyRefunded)->exists();
    }

    public function isMontyPayAuthorized(): bool
    {
        return $this->montyPayPaymentIn(PaymentState::Authorized)->exists();
    }

    /** Start a checkout session for this model and link the payment to it. */
    public function startMontyPayCheckout(array $orderDetails, array $customerDetails, string $operation = 'purchase', array $urls = [], array $options = [], ?array $billingAddress = null): array
    {
        return MontyPay::initCheckout($orderDetails, $customerDetails, $operation, $urls, ['payable' => $this] + $options, $billingAddress);
    }

    /** Refund the settled payment (full amount unless one is given). */
    public function refundMontyPay(?string $amount = null): array
    {
        return $this->montyPayPaymentIn(PaymentState::Paid, PaymentState::PartiallyRefunded)->latest('id')->firstOrFail()->refund($amount);
    }

    public function captureMontyPay(?string $amount = null): array
    {
        return $this->montyPayPaymentIn(PaymentState::Authorized)->latest('id')->firstOrFail()->capture($amount);
    }

    public function voidMontyPay(): array
    {
        return $this->montyPayPaymentIn(PaymentState::Authorized)->latest('id')->firstOrFail()->void();
    }

    private function montyPayPaymentIn(PaymentState ...$states): MorphMany
    {
        return $this->montyPayPayments()->whereIn('state', array_map(fn ($s) => $s->value, $states));
    }
}
