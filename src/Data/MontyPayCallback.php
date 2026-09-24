<?php

namespace AhmadChebbo\LaravelMontypay\Data;

use AhmadChebbo\LaravelMontypay\Enums\CallbackStatus;
use AhmadChebbo\LaravelMontypay\Enums\CallbackType;
use AhmadChebbo\LaravelMontypay\Enums\OrderStatus;

/**
 * A callback, with the decision logic from the MontyPay docs built in:
 * completion depends on type + status + order_status together.
 */
final class MontyPayCallback
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderNumber,
        public readonly ?string $orderAmount,
        public readonly ?string $orderCurrency,
        public readonly ?string $orderDescription,
        public readonly ?CallbackType $type,
        public readonly ?CallbackStatus $status,
        public readonly ?OrderStatus $orderStatus,
        public readonly ?string $reason,
        public readonly ?string $cardToken,
        public readonly ?string $recurringInitTransId,
        public readonly ?string $recurringToken,
        public readonly ?string $scheduleId,
        /** `active` or `cancelled` when the payer confirmed the consent checkbox; `ignored` when they did not. */
        public readonly ?string $consentState,
        public readonly ?string $consentId,
        public readonly mixed $customData,
        /** True when built by `montypay:reconcile` from the status API rather than a real callback. */
        public readonly bool $reconciled,
        /** The full callback payload as received. */
        public readonly array $raw,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $str = fn (string $key) => isset($data[$key]) && $data[$key] !== '' && ! is_array($data[$key]) ? (string) $data[$key] : null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            orderNumber: (string) ($data['order_number'] ?? ''),
            orderAmount: $str('order_amount'),
            orderCurrency: $str('order_currency'),
            orderDescription: $str('order_description'),
            type: CallbackType::tryFrom((string) ($data['type'] ?? '')),
            status: CallbackStatus::tryFrom((string) ($data['status'] ?? '')),
            orderStatus: OrderStatus::tryFrom((string) ($data['order_status'] ?? '')),
            reason: $str('reason'),
            cardToken: $str('card_token'),
            recurringInitTransId: $str('recurring_init_trans_id'),
            recurringToken: $str('recurring_token'),
            scheduleId: $str('schedule_id'),
            // A declined consent arrives as extended_data[consent_state]=ignored, not as consent_state.
            consentState: $str('consent_state') ?? (is_array($data['extended_data'] ?? null) ? ($data['extended_data']['consent_state'] ?? null) : null),
            consentId: $str('consent_id'),
            customData: $data['custom_data'] ?? null,
            reconciled: (bool) ($data['reconciled'] ?? false),
            raw: $data,
        );
    }

    /** Final success: money moved, fulfil the order. */
    public function isSettled(): bool
    {
        return $this->status === CallbackStatus::Success
            && $this->type?->settles() === true
            && $this->orderStatus === OrderStatus::Settled;
    }

    /** DMS authorisation: funds held but not captured. Capture or void it. */
    public function isAuthorized(): bool
    {
        return $this->status === CallbackStatus::Success
            && $this->type === CallbackType::Sale
            && $this->orderStatus === OrderStatus::Pending;
    }

    public function isFailed(): bool
    {
        return $this->status === CallbackStatus::Fail;
    }

    public function isRefunded(): bool
    {
        return $this->type === CallbackType::Refund && $this->status === CallbackStatus::Success;
    }

    /** A partial refund keeps order_status=settled. */
    public function isPartialRefund(): bool
    {
        return $this->isRefunded() && $this->orderStatus === OrderStatus::Settled;
    }

    public function isVoided(): bool
    {
        return $this->type === CallbackType::Void && $this->status === CallbackStatus::Success;
    }

    public function isChargeback(): bool
    {
        return $this->type === CallbackType::Chargeback;
    }

    public function isReversal(): bool
    {
        return $this->type === CallbackType::Reversal;
    }

    /** Uncertain outcome. Do not fulfil; reconcile manually. */
    public function isUndefined(): bool
    {
        return $this->status === CallbackStatus::Undefined;
    }

    /** 3ds / redirect / init: not a completed payment, wait for the next callback. */
    public function isIntermediary(): bool
    {
        return $this->type?->isIntermediary() === true;
    }

    /** The first payment of a subscription settled. A recurring_token is what tells it apart from a one-off sale. */
    public function startedSubscription(): bool
    {
        return $this->isSettled() && $this->recurringToken !== null && $this->type !== CallbackType::Recurring;
    }

    /** The payer left the recurring consent checkbox unticked, so this was processed as a one-time purchase. */
    public function ignoredConsent(): bool
    {
        return $this->consentState === 'ignored';
    }
}
