<?php

namespace AhmadChebbo\LaravelMontypay\Services;

use AhmadChebbo\LaravelMontypay\Data\MontyPayCallback;
use AhmadChebbo\LaravelMontypay\Enums\CallbackType;
use AhmadChebbo\LaravelMontypay\Enums\OrderStatus;
use AhmadChebbo\LaravelMontypay\Enums\PaymentState;
use AhmadChebbo\LaravelMontypay\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps montypay_payments in step with checkout sessions and verified callbacks.
 */
class PaymentRecorder
{
    private static bool $tableExists = false;

    public function enabled(): bool
    {
        if (! config('montypay.payments.enabled', true)) {
            return false;
        }

        // Only a positive answer is cached, so running `migrate` later takes effect without a restart.
        return self::$tableExists = self::$tableExists || Schema::hasTable('montypay_payments');
    }

    /** Called when MontyPay accepted a session. */
    public function sessionCreated(array $order, string $operation, ?Model $payable = null): ?Payment
    {
        return $this->enabled() ? Payment::create([
            'payable_type' => $payable?->getMorphClass(),
            'payable_id' => $payable?->getKey(),
            'order_number' => $order['number'],
            'operation' => $operation,
            'amount' => $order['amount'],
            'currency' => $order['currency'],
            'description' => $order['description'],
            'state' => PaymentState::Created,
        ]) : null;
    }

    /** What was sent for an order (amount, currency, description), for verifying the customer-return hash. */
    public function orderFor(string $orderNumber): ?array
    {
        if ($orderNumber === '' || ! $this->enabled()) {
            return null;
        }

        return Payment::where('order_number', $orderNumber)->latest('id')->first()?->only(['amount', 'currency', 'description']);
    }

    /** Apply a verified callback (or reconciled status) to its payment. */
    public function apply(MontyPayCallback $cb): ?Payment
    {
        if (! $this->enabled() || $cb->id === '') {
            return null;
        }

        $payment = Payment::where('payment_id', $cb->id)->first()
            ?? Payment::where('order_number', $cb->orderNumber)->latest('id')->first();

        if (! $payment) {
            if (! config('montypay.payments.create_from_callbacks', true)) {
                return null;
            }

            $payment = new Payment(['order_number' => $cb->orderNumber, 'state' => PaymentState::Created]);
        }

        $target = $this->targetState($cb);

        if ($target && $payment->state->canBecome($target)) {
            $payment->state = $target;
        }

        $payment->payment_id = $cb->id;
        $payment->last_callback_at = now();
        $payment->operation ??= $this->operationFor($cb);
        $payment->amount ??= $cb->orderAmount;
        $payment->currency ??= $cb->orderCurrency;
        $payment->description ??= $cb->orderDescription;

        if ($cb->isFailed() || $cb->isUndefined()) {
            $payment->reason = $cb->reason ?? $payment->reason;
        }

        foreach ([
            'recurring_init_trans_id' => $cb->recurringInitTransId,
            'recurring_token' => $cb->recurringToken,
            'schedule_id' => $cb->scheduleId,
            'consent_id' => $cb->consentId,
            'consent_state' => $cb->consentState,
            'card_token' => $cb->cardToken,
        ] as $column => $value) {
            if ($value !== null) {
                $payment->{$column} = $value;
            }
        }

        $payment->save();

        return $payment;
    }

    /** The state a callback moves the payment to, or null to leave it alone. */
    protected function targetState(MontyPayCallback $cb): ?PaymentState
    {
        return match (true) {
            $cb->isUndefined() => PaymentState::Undefined,
            // A failed refund/void/capture says nothing about the payment itself.
            $cb->isFailed() => in_array($cb->type, [CallbackType::Refund, CallbackType::Void, CallbackType::Capture], true) ? null : PaymentState::Failed,
            $cb->isSettled() => PaymentState::Paid,
            $cb->isAuthorized() => PaymentState::Authorized,
            $cb->isRefunded() => $cb->orderStatus === OrderStatus::Refund ? PaymentState::Refunded : PaymentState::PartiallyRefunded,
            $cb->isVoided() => PaymentState::Voided,
            $cb->isChargeback() => PaymentState::Chargeback,
            $cb->isReversal() => PaymentState::Reversed,
            default => PaymentState::Pending, // 3ds / redirect / init / waiting
        };
    }

    protected function operationFor(MontyPayCallback $cb): ?string
    {
        return match ($cb->type) {
            CallbackType::Sale => 'purchase',
            CallbackType::Debit, CallbackType::Credit, CallbackType::Transfer => $cb->type->value,
            default => null,
        };
    }
}
