<?php

namespace AhmadChebbo\LaravelMontypay\Facades;

use AhmadChebbo\LaravelMontypay\Services\MontyPayService;
use AhmadChebbo\LaravelMontypay\Testing\FakeMontyPayService;
use AhmadChebbo\LaravelMontypay\Testing\SignedCallback;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array initCheckout(array $orderDetails, array $customerDetails, string $operation, array $urls = [], array $options = [], ?array $billingAddress = null)
 * @method static array createHostedSession(array $orderDetails, array $customerDetails, string $returnUrl, string $operation = 'purchase', array $urls = [], array $options = [], ?array $billingAddress = null)
 * @method static array payWithHostedFields(string $sessionToken, array $payer, string $operation = 'purchase', ?int $timeout = null)
 * @method static array browserInfo(\Illuminate\Http\Request $request, array $client = [])
 * @method static string hostedFieldsScriptUrl()
 * @method static array capture(string $paymentId, string $amount, ?int $timeout = null)
 * @method static array refund(string $paymentId, string $amount, ?int $timeout = null)
 * @method static array void(string $paymentId, ?int $timeout = null)
 * @method static array retry(string $paymentId, ?int $timeout = null)
 * @method static array recurring(string $recurringInitTransId, string $recurringToken, array $order, array $extra = [], ?int $timeout = null)
 * @method static array cardCredit(string $orderId, string $amount, string $currency, string $description, array $payee, ?int $timeout = null)
 * @method static array getTransactionStatusByPaymentId(string $paymentId, ?int $timeout = null)
 * @method static array getTransactionStatusByOrderId(string $orderId, ?int $timeout = null, ?int $size = null)
 * @method static bool|null verifyReturn(array $query, ?array $order = null)
 * @method static MontyPayService environment(string $environment)
 * @method static string currentEnvironment()
 * @method static bool isSandbox()
 *
 * @see MontyPayService
 */
class MontyPay extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'montypay';
    }

    /**
     * Replace the client with one that makes no HTTP calls and records what was sent.
     * Also switches to a fake sandbox configuration so signed callbacks verify in tests.
     */
    public static function fake(array $responses = []): FakeMontyPayService
    {
        config([
            'montypay.environment' => 'sandbox',
            'montypay.environments.sandbox' => [
                'checkout_url' => 'https://montypay.test',
                'merchant_key' => 'fake-merchant-key',
                'merchant_password' => 'fake-merchant-password',
            ],
        ]);

        $fake = new FakeMontyPayService($responses);

        static::swap($fake);
        static::$app->instance(MontyPayService::class, $fake);

        return $fake;
    }

    /** A callback payload with a valid hash. See SignedCallback::make(). */
    public static function signedCallback(array $overrides = [], ?string $password = null): array
    {
        return SignedCallback::make($overrides, $password);
    }
}
