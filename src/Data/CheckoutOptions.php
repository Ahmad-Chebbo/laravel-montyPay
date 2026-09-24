<?php

namespace AhmadChebbo\LaravelMontypay\Data;

use AhmadChebbo\LaravelMontypay\Exceptions\MontyPayException;
use Illuminate\Database\Eloquent\Model;

class CheckoutOptions
{
    public array $paymentMethods;
    public bool $withTransactionFee;
    public float $transactionFeePercentage;
    public bool $reqToken;
    public int $timeout;
    public ?string $cardToken;

    /** Model (Order, Invoice, ...) to link the payment record to. */
    public ?Model $payable;

    /** Recurring setup: `true`, or schedule_id, start_date, amount, consent_required. */
    public array $recurring;

    /** Extra session parameters passed through as-is (auth, session_expiry, custom_data, ...). */
    public array $extra;

    public function __construct(array $options = [])
    {
        $this->paymentMethods = $options['payment_methods'] ?? config('montypay.payment_methods');
        $this->withTransactionFee = (bool) ($options['with_transaction_fee'] ?? config('montypay.transaction_fee.enabled'));
        $this->transactionFeePercentage = (float) ($options['transaction_fee_percentage'] ?? config('montypay.transaction_fee.percentage'));
        $this->reqToken = (bool) ($options['req_token'] ?? config('montypay.request_options.req_token'));
        $this->timeout = (int) ($options['timeout'] ?? config('montypay.request_options.timeout'));
        $this->cardToken = $options['card_token'] ?? null;
        $this->payable = $options['payable'] ?? null;
        $this->extra = $options['extra'] ?? [];

        $recurring = $options['recurring'] ?? false;
        $this->recurring = $recurring === true ? ['init' => true] : (array) $recurring;
    }

    /**
     * Session parameters for a recurring payment. The docs require a schedule_id for the
     * trial date, the schedule amount and the consent checkbox; the API would reject them otherwise.
     *
     * @throws MontyPayException
     */
    public function recurringParams(): array
    {
        $r = $this->recurring;

        if (! $r) {
            return [];
        }

        foreach (['start_date', 'amount', 'consent_required'] as $key) {
            if (! empty($r[$key]) && empty($r['schedule_id'])) {
                throw new MontyPayException("recurring.{$key} requires recurring.schedule_id.");
            }
        }

        $start = $r['start_date'] ?? null;

        return array_filter([
            'recurring_init' => true,
            'schedule_id' => $r['schedule_id'] ?? null,
            'schedule_start_date' => $start instanceof \DateTimeInterface ? $start->format('Y-m-d') : $start,
            'payment_schedule_amount' => isset($r['amount']) ? (string) $r['amount'] : null,
            'recurring_consent_required' => isset($r['consent_required']) ? (bool) $r['consent_required'] : null,
        ], fn ($value) => $value !== null);
    }
}
