<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration is used to set the default values for the MontyPay API.
    |
    */

    /*
    | Active environment: 'sandbox' or 'production'. Sandbox moves no real money and
    | uses your merchant *Test key*. Switch per request with
    | MontyPay::environment('sandbox')->initCheckout(...).
    |
    | The callback route always verifies against the active environment's password.
    */
    'environment' => env('MONTYPAY_ENV', 'production'),

    'environments' => [
        // MontyPay gives every account its own sandbox base URL (admin: API -> URLs -> Sandbox),
        // so there is no default. Missing values fail with a message naming them.
        'sandbox' => [
            'checkout_url' => env('MONTYPAY_SANDBOX_URL'),
            'merchant_key' => env('MONTYPAY_SANDBOX_MERCHANT_KEY'),
            'merchant_password' => env('MONTYPAY_SANDBOX_MERCHANT_PASSWORD'),
        ],

        // The un-prefixed MONTYPAY_CHECKOUT_URL / MERCHANT_KEY / MERCHANT_PASSWORD from
        // earlier versions still work here as fallbacks.
        'production' => [
            'checkout_url' => env('MONTYPAY_PRODUCTION_URL') ?: env('MONTYPAY_CHECKOUT_URL') ?: 'https://checkout.montypay.com',
            'merchant_key' => env('MONTYPAY_PRODUCTION_MERCHANT_KEY') ?: env('MONTYPAY_MERCHANT_KEY'),
            'merchant_password' => env('MONTYPAY_PRODUCTION_MERCHANT_PASSWORD') ?: env('MONTYPAY_MERCHANT_PASSWORD'),
        ],
    ],

    /*
    | Hash digest. Use 'sha256' only if "Use SHA256 encryption algorithm for hash"
    | is enabled for your protocol mapping; otherwise 'md5'.
    */
    'hash_algorithm' => env('MONTYPAY_HASH_ALGORITHM', 'md5'),

    /*
    |--------------------------------------------------------------------------
    | Default Payment Methods
    |--------------------------------------------------------------------------
    |
    | This configuration is used to set the default payment methods for the MontyPay payment gateway.
    |
    */
    'payment_methods' => [
        'card',
        // Add other payment methods as needed
        // 'apple_pay',
        // 'google_pay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Fee Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration is used to set the default transaction fee for the MontyPay payment gateway.
    |
    */
    'transaction_fee' => [
        'enabled' => env('MONTYPAY_TRANSACTION_FEE_ENABLED', false),
        'percentage' => env('MONTYPAY_TRANSACTION_FEE_PERCENTAGE', 3.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Billing Address
    |--------------------------------------------------------------------------
    |
    | Optional fallback billing address. Empty by default: unset values are
    | omitted from the request rather than sent as fake customer data.
    |
    */
    'default_billing_address' => [
        'country' => env('MONTYPAY_DEFAULT_COUNTRY'),
        'state' => env('MONTYPAY_DEFAULT_STATE'),
        'city' => env('MONTYPAY_DEFAULT_CITY'),
        'district' => env('MONTYPAY_DEFAULT_DISTRICT'),
        'address' => env('MONTYPAY_DEFAULT_ADDRESS'),
        'house_number' => env('MONTYPAY_DEFAULT_HOUSE_NUMBER'),
        'zip' => env('MONTYPAY_DEFAULT_ZIP'),
        'phone' => env('MONTYPAY_DEFAULT_PHONE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Options
    |--------------------------------------------------------------------------
    |
    | This configuration is used to set the default request options for the MontyPay payment gateway.
    |
    */
    'request_options' => [
        /*
        |--------------------------------------------------------------------------
        | Request Timeout
        |--------------------------------------------------------------------------
        |
        | This configuration is used to set the request timeout for the MontyPay payment gateway.
        |
        */
        'timeout' => env('MONTYPAY_REQUEST_TIMEOUT', 30),
        /*
        |--------------------------------------------------------------------------
        | Request Token
        |--------------------------------------------------------------------------
        |
        | This configuration is used to set the request token for the MontyPay payment gateway.
        | In case you want to save the credit card token for future use, you can set this to true.
        | Please refer to the MontyPay documentation for more information. [https://docs.montypay.com/checkout_integration#card-data-tokenization]
        |
        */
        'req_token' => env('MONTYPAY_REQ_TOKEN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default URLs and Routes
    |--------------------------------------------------------------------------
    |
    | This configuration is used to set the default URLs and routes for the MontyPay payment gateway.
    |
    */
    'routes' => [
        'prefix' => 'montypay',
        /*
        |--------------------------------------------------------------------------
        | Middleware
        |--------------------------------------------------------------------------
        |
        | This configuration is used to set the middleware for the MontyPay payment gateway.
        | Add any middleware you want to use for the MontyPay routes.
        |
        */
        'middleware' => ['web'], // Add any middleware you want

        // Middleware for the webhook route. Keep it free of `web` (session/CSRF):
        // MontyPay posts server-to-server and is authenticated by the hash.
        'callback_middleware' => [],
    ],

    /*
    | App paths used for session return URLs. Leave null to use the package
    | routes (montypay.success / cancel / decline). The webhook (callback) URL
    | is not sent per request: it is configured in the MontyPay admin panel and
    | should point at route('montypay.callback').
    */
    'urls' => [
        'success' => env('MONTYPAY_SUCCESS_URL'),
        'cancel' => env('MONTYPAY_CANCEL_URL'),
        'expiry' => env('MONTYPAY_EXPIRY_URL'),
        'error' => env('MONTYPAY_ERROR_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback Settings
    |--------------------------------------------------------------------------
    |
    | This configuration is used to set the callback settings for the MontyPay payment gateway.
    |
    */
    'callback' => [
        'verify_signature' => env('MONTYPAY_VERIFY_CALLBACK_SIGNATURE', true),
        'store_notifications' => env('MONTYPAY_STORE_NOTIFICATIONS', true),

        // Fire the events from a queued job instead of inside the webhook request.
        'queue' => env('MONTYPAY_QUEUE_CALLBACKS', false),
        'queue_connection' => env('MONTYPAY_QUEUE_CONNECTION'),
        'queue_name' => env('MONTYPAY_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment records
    |--------------------------------------------------------------------------
    |
    | Keep a row per order attempt in montypay_payments, updated from verified
    | callbacks. Link it to your own model with options['payable'] => $order
    | (see the HasMontyPayPayments trait).
    |
    */
    'payments' => [
        'enabled' => env('MONTYPAY_PAYMENTS_ENABLED', true),
        // Also create a record when a callback arrives for an order this package did not start.
        'create_from_callbacks' => env('MONTYPAY_PAYMENTS_FROM_CALLBACKS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation (montypay:reconcile)
    |--------------------------------------------------------------------------
    |
    | MontyPay delivers each callback once with no retries. The command polls the
    | status API for payments whose last known state is not final. Requires
    | callback.store_notifications.
    |
    */
    'reconcile' => [
        'older_than' => env('MONTYPAY_RECONCILE_OLDER_THAN', 10),   // minutes since the last callback
        'max_age' => env('MONTYPAY_RECONCILE_MAX_AGE', 1440),        // stop chasing payments older than this (minutes)
        'limit' => env('MONTYPAY_RECONCILE_LIMIT', 100),             // max payments polled per run
    ],
];
