<?php

namespace AhmadChebbo\LaravelMontypay\Services;

use AhmadChebbo\LaravelMontypay\Data\BillingAddress;
use AhmadChebbo\LaravelMontypay\Data\CheckoutOptions;
use AhmadChebbo\LaravelMontypay\Exceptions\MontyPayException;
use AhmadChebbo\LaravelMontypay\Support\Credentials;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class MontyPayService
{
    protected Credentials $credentials;

    /**
     * @param  string|null  $environment  'sandbox' or 'production'; null uses `montypay.environment`
     */
    public function __construct(?string $environment = null)
    {
        $this->credentials = Credentials::for($environment);
    }

    /**
     * A client bound to another environment, leaving this one untouched:
     * MontyPay::environment('sandbox')->initCheckout(...)
     */
    public function environment(string $environment): static
    {
        return new static($environment);
    }

    public function currentEnvironment(): string
    {
        return $this->credentials->environment;
    }

    public function isSandbox(): bool
    {
        return $this->credentials->isSandbox();
    }

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    */

    /**
     * Create a Checkout payment session; redirect the payer to `redirect_url`.
     *
     * $options: payment_methods, req_token, card_token, timeout, payable (a model to link the payment to),
     * recurring (schedule_id, start_date, amount, consent_required), extra (any other session parameter).
     *
     * @throws MontyPayException
     */
    public function initCheckout(array $orderDetails, array $customerDetails, string $operation, array $urls = [], array $options = [], ?array $billingAddress = null): array
    {
        return $this->createSession('/api/v1/session', $orderDetails, $customerDetails, $operation, $urls, $options, $billingAddress);
    }

    /**
     * Create a Hosted Payment Fields session; returns ['token' => ...] for the HostedFields SDK.
     * Send the payer's real IP as $customerDetails['ip'] so the connector does not see your server's.
     *
     * @throws MontyPayException
     */
    public function createHostedSession(array $orderDetails, array $customerDetails, string $returnUrl, string $operation = 'purchase', array $urls = [], array $options = [], ?array $billingAddress = null): array
    {
        $this->assertHostedOperation($operation);
        $options['extra']['return_url'] = $returnUrl;

        return $this->createSession('/api/v1/session/token', $orderDetails, $customerDetails, $operation, $urls, $options, $billingAddress, true);
    }

    /**
     * Process the card data the HostedFields SDK collected. `result` is success, decline, redirect, waiting or undefined.
     *
     * @param  array  $payer  name (required), email, birth_date, selected_language, billing_address, browser_info
     */
    public function payWithHostedFields(string $sessionToken, array $payer, string $operation = 'purchase', ?int $timeout = null): array
    {
        $this->assertHostedOperation($operation);

        $body = ['with_hosted_fields' => true]
            + Arr::only($payer, ['name', 'email', 'birth_date', 'selected_language', 'billing_address', 'browser_info']);

        return $this->send("/api/v1/processing/{$operation}/card", $body, $timeout, 'process hosted-fields payment', ['Token' => $sessionToken]);
    }

    /** browser_info for payWithHostedFields(); accept_header must come from the payer's request, not be hardcoded. */
    public function browserInfo(Request $request, array $client = []): array
    {
        return array_filter($client + [
            'accept_header' => $request->header('Accept'),
            'user_agent' => $request->userAgent(),
            'language' => $request->getPreferredLanguage(),
        ]);
    }

    public function hostedFieldsScriptUrl(): string
    {
        return $this->credentials->checkoutUrl . '/sdk/hosted-fields.js';
    }

    /*
    |--------------------------------------------------------------------------
    | Post-payment operations
    |--------------------------------------------------------------------------
    */

    /** Capture funds held by an `auth` (DMS) session. */
    public function capture(string $paymentId, string $amount, ?int $timeout = null): array
    {
        return $this->paymentOperation('capture', $paymentId, $amount, $timeout);
    }

    /** Refund a settled payment, fully or partially. */
    public function refund(string $paymentId, string $amount, ?int $timeout = null): array
    {
        return $this->paymentOperation('refund', $paymentId, $amount, $timeout);
    }

    /** Release an authorised, not-yet-settled payment. */
    public function void(string $paymentId, ?int $timeout = null): array
    {
        return $this->paymentOperation('void', $paymentId, null, $timeout);
    }

    /** Retry a recurring payment that received a soft decline. */
    public function retry(string $paymentId, ?int $timeout = null): array
    {
        return $this->paymentOperation('retry', $paymentId, null, $timeout);
    }

    /**
     * Charge a recurring chain started with recurring_init. Currency is inherited from the initial payment.
     *
     * @param  array{number: string, amount: string, description: string}  $order
     * @param  array{schedule_id?: string, custom_data?: array}  $extra
     */
    public function recurring(string $recurringInitTransId, string $recurringToken, array $order, array $extra = [], ?int $timeout = null): array
    {
        $order = Arr::only($order, ['number', 'amount', 'description']);
        $order['amount'] = (string) $order['amount'];

        $hash = PaymentSignatureService::generateRecurringSignature(
            $recurringInitTransId, $recurringToken, $order['number'], $order['amount'], $order['description'], $this->credentials->password
        );

        return $this->send('/api/v1/payment/recurring', Arr::only($extra, ['schedule_id', 'custom_data']) + [
            'merchant_key' => $this->credentials->merchantKey,
            'recurring_init_trans_id' => $recurringInitTransId,
            'recurring_token' => $recurringToken,
            'order' => $order,
            'hash' => $hash,
        ], $timeout, 'process recurring payment');
    }

    /**
     * Payout straight to a card, without the Checkout page.
     *
     * @param  array  $payee  card_number or card_token, plus optional payee_first_name, payee_last_name, payee_email, payee_ip
     */
    public function cardCredit(string $orderId, string $amount, string $currency, string $description, array $payee, ?int $timeout = null): array
    {
        $hash = PaymentSignatureService::generateCardCreditSignature($orderId, $amount, $currency, $description, $this->credentials->password);

        return $this->send('/api/v1/payment/card/credit', Arr::only($payee, [
            'card_number', 'card_token', 'payee_first_name', 'payee_last_name', 'payee_email', 'payee_ip',
        ]) + [
            'merchant_key' => $this->credentials->merchantKey,
            'order_id' => $orderId,
            'order_amount' => $amount,
            'order_currency' => $currency,
            'order_description' => $description,
            'hash' => $hash,
        ], $timeout, 'send card credit');
    }

    /*
    |--------------------------------------------------------------------------
    | Status and verification
    |--------------------------------------------------------------------------
    */

    public function getTransactionStatusByPaymentId(string $paymentId, ?int $timeout = null): array
    {
        return $this->status(['payment_id' => $paymentId], PaymentSignatureService::generateTransactionStatusSignatureByPaymentId($paymentId, $this->credentials->password), $timeout);
    }

    /**
     * @param  int|null  $size  1..100: return that many most recent attempts for the order (an array) instead of only the newest
     */
    public function getTransactionStatusByOrderId(string $orderId, ?int $timeout = null, ?int $size = null): array
    {
        if ($size !== null && ($size < 1 || $size > 100)) {
            throw new MontyPayException('size must be within 1..100.');
        }

        return $this->status(
            ['order_id' => $orderId] + ($size ? ['size' => (string) $size] : []),
            PaymentSignatureService::generateTransactionStatusSignatureByOrderId($orderId, $this->credentials->password),
            $timeout
        );
    }

    /**
     * Verify the hash MontyPay appends to success_url / cancel_url (needs "Return parameters" enabled in Protocol Mapping).
     * The query carries no amount, currency or description, so they come from $order or from the stored payment.
     *
     * @param  array|null  $order  amount, currency, description as you sent them
     * @return bool|null null when it cannot be checked (no hash returned, or no known order)
     */
    public function verifyReturn(array $query, ?array $order = null): ?bool
    {
        $order ??= app(PaymentRecorder::class)->orderFor((string) ($query['order_id'] ?? ''));

        if (! ($query['hash'] ?? null) || ! ($query['payment_id'] ?? null) || ! ($query['order_id'] ?? null)
            || in_array(null, [$order['amount'] ?? null, $order['currency'] ?? null, $order['description'] ?? null], true)) {
            return null;
        }

        $expected = PaymentSignatureService::generateReturnSignature(
            $query['payment_id'], $query['order_id'], $order['amount'], $order['currency'], $order['description'], $this->credentials->password
        );

        return hash_equals($expected, strtolower((string) $query['hash']));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    protected function createSession(string $path, array $order, array $customer, string $operation, array $urls, array $options, ?array $billing, bool $hosted = false): array
    {
        $options = new CheckoutOptions($options);

        $cardToken = $options->cardToken ?? $order['cardToken'] ?? null; // legacy: token inside the order array
        unset($order['cardToken']);

        if ($options->withTransactionFee) {
            $order['amount'] = number_format(round((float) $order['amount'] * (1 + $options->transactionFeePercentage / 100), 2), 2, '.', '');
        }
        $order['amount'] = (string) $order['amount']; // the hashed amount must be byte-identical to the sent one

        $urls = array_filter($urls) + $this->defaultUrls();

        $payload = [
            'merchant_key' => $this->credentials->merchantKey,
            'operation' => $operation,
            'success_url' => $urls['success_url'],
            'cancel_url' => $urls['cancel_url'],
            'error_url' => $urls['error_url'],
            'hash' => PaymentSignatureService::generateAuthenticationSignature(
                $order['number'], $order['amount'], $order['currency'], $order['description'], $this->credentials->password
            ),
            'order' => $order,
            'customer' => $customer,
            'billing_address' => (new BillingAddress($billing ?? []))->toArray(),
        ];

        if (! $hosted) {
            $payload += [
                'methods' => $options->paymentMethods,
                'expiry_url' => $urls['expiry_url'] ?? null,
                'card_token' => $cardToken ? [$cardToken] : null,
                'req_token' => $cardToken ? null : $options->reqToken, // ignored by the API when a token is sent
            ];
        }

        $payload = array_filter($payload, fn ($value) => $value !== null && $value !== []);

        // Anything else the caller passed; the signed and identity fields above always win.
        $payload += Arr::except($options->recurringParams() + $options->extra, ['merchant_key', 'hash', 'order']);

        $session = $this->send($path, $payload, $options->timeout, $hosted ? 'create hosted session' : 'initialize checkout');

        // Recorded only after MontyPay accepted the session. `amount` includes any transaction fee.
        app(PaymentRecorder::class)->sessionCreated($order, $operation, $options->payable);

        return $session;
    }

    protected function paymentOperation(string $operation, string $paymentId, ?string $amount, ?int $timeout): array
    {
        $password = $this->credentials->password;

        $hash = $amount === null
            ? PaymentSignatureService::generateVoidSignature($paymentId, $password)
            : PaymentSignatureService::generateRefundSignature($paymentId, $amount, $password);

        return $this->send("/api/v1/payment/{$operation}", array_filter([
            'merchant_key' => $this->credentials->merchantKey,
            'payment_id' => $paymentId,
            'amount' => $amount,
            'hash' => $hash,
        ]), $timeout, "{$operation} payment");
    }

    protected function status(array $identifier, string $hash, ?int $timeout): array
    {
        return $this->send('/api/v1/payment/status', $identifier + [
            'merchant_key' => $this->credentials->merchantKey,
            'hash' => $hash,
        ], $timeout, 'get transaction status');
    }

    protected function defaultUrls(): array
    {
        $resolve = fn (string $key, string $route) => config("montypay.urls.$key")
            ? url(config("montypay.urls.$key"))
            : route($route);

        return [
            'success_url' => $resolve('success', 'montypay.success'),
            'cancel_url' => $resolve('cancel', 'montypay.cancel'),
            'error_url' => $resolve('error', 'montypay.decline'),
        ];
    }

    protected function assertHostedOperation(string $operation): void
    {
        if (! in_array($operation, ['purchase', 'debit'], true)) {
            throw new MontyPayException("Hosted Payment Fields support 'purchase' and 'debit', not '{$operation}'.");
        }
    }

    /**
     * POST to the Checkout API, surfacing the API's own error code and message.
     *
     * @throws MontyPayException
     */
    protected function send(string $path, array $payload, ?int $timeout, string $action, array $headers = []): array
    {
        // Fail before any HTTP call: never send with the wrong environment's (or no) credentials.
        $this->credentials->assertConfigured();

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout ?? config('montypay.request_options.timeout'))
                ->acceptJson()
                ->post($this->credentials->checkoutUrl . $path, $payload);
        } catch (\Throwable $e) {
            throw new MontyPayException("Failed to {$action}: " . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $body = $response->json() ?? [];
        $errors = $body['errors'] ?? [];

        throw new MontyPayException(
            "Failed to {$action}: " . ($errors[0]['error_message'] ?? $body['error_message'] ?? $body['message'] ?? 'Unknown error'),
            (int) ($errors[0]['error_code'] ?? $body['error_code'] ?? $response->status()),
            null,
            $errors
        );
    }
}
