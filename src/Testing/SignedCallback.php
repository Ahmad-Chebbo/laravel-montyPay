<?php

namespace AhmadChebbo\LaravelMontypay\Testing;

use AhmadChebbo\LaravelMontypay\Services\PaymentSignatureService;
use Illuminate\Support\Str;

/**
 * Builds a callback payload with a valid hash, for testing your listeners and the webhook route:
 *
 *     $this->post(route('montypay.callback'), MontyPay::signedCallback([
 *         'order_number' => $order->number, 'type' => 'sale', 'status' => 'success', 'order_status' => 'settled',
 *     ]))->assertOk();
 */
final class SignedCallback
{
    /**
     * A settled sale by default; any field can be overridden and the hash is computed from the final values.
     * Uses the active environment's password unless one is given.
     */
    public static function make(array $overrides = [], ?string $password = null): array
    {
        $callback = $overrides + [
            'id' => (string) Str::uuid(),
            'order_number' => 'order-1',
            'order_amount' => '10.00',
            'order_currency' => 'USD',
            'order_description' => 'Test order',
            'order_status' => 'settled',
            'type' => 'sale',
            'status' => 'success',
        ];

        $callback['hash'] ??= PaymentSignatureService::generateCallbackSignature(
            $callback['id'], $callback['order_number'], $callback['order_amount'],
            $callback['order_currency'], $callback['order_description'], $password
        );

        return $callback;
    }
}
