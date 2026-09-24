# Laravel MontyPay

A Laravel package for the [MontyPay](https://docs.montypay.com) **Checkout** API: payment sessions, Hosted Payment Fields, signed webhooks, post-payment operations (capture, refund, void, retry, recurring, card payouts), payment records and test helpers.

> **⚠️ UNOFFICIAL PACKAGE - NOT AFFILIATED WITH MontyPay**
>
> This is an unofficial, community-created package. Verify behaviour against the official [MontyPay documentation](https://docs.montypay.com) before going to production.

Requires PHP 8.2+ and Laravel 10+.

- [Installation](#installation) · [Configuration](#configuration) · [Environments](#environments)
- [Payments](#payments): [sessions](#creating-a-payment) · [recurring & consent](#recurring-payments-and-consent) · [hosted fields](#hosted-payment-fields) · [post-payment](#post-payment-operations) · [status & return check](#status-and-return-verification)
- [Handling payments](#handling-payments): [events](#events) · [payment records](#payment-records) · [queue](#queued-processing) · [reconcile](#reconciling-missed-callbacks)
- [Testing](#testing) · [Upgrading](#upgrading-from-10) · [Not included](#not-included)

## Installation

```bash
composer require ahmad-chebbo/laravel-montypay
php artisan montypay:install
```

The install command publishes the config and migration, adds the missing `MONTYPAY_*` keys to `.env` and `.env.example` (existing values are never overwritten), offers to run `migrate`, and prints the webhook URL to register with MontyPay. Options: `--views` (also publish the return views), `--migrate`, `--force`, `--no-env`.

Manual alternative: `php artisan vendor:publish --tag=montypay-config` (also `montypay-migrations`, `montypay-views`), then `php artisan migrate`.

For a local path package, add `{ "type": "path", "url": "../packages/ahmad-chebbo/laravel-montyPay", "options": { "symlink": true } }` to your app's `repositories` and run `composer require ahmad-chebbo/laravel-montypay:@dev`.

## Configuration

```
MONTYPAY_ENV=sandbox                      # sandbox | production (default: production)

MONTYPAY_SANDBOX_URL=https://...          # given to you by MontyPay; admin: API -> URLs -> Sandbox
MONTYPAY_SANDBOX_MERCHANT_KEY=...         # merchant "Test key"
MONTYPAY_SANDBOX_MERCHANT_PASSWORD=...

MONTYPAY_PRODUCTION_URL=https://checkout.montypay.com
MONTYPAY_PRODUCTION_MERCHANT_KEY=...
MONTYPAY_PRODUCTION_MERCHANT_PASSWORD=...
```

The password is the merchant **Password** from the admin panel (Merchants → your merchant → Password), not the merchant key.

| Variable | Default | Purpose |
|---|---|---|
| `MONTYPAY_HASH_ALGORITHM` | `md5` | `sha256` only if "Use SHA256 encryption algorithm for hash" is enabled for your protocol mapping. |
| `MONTYPAY_TRANSACTION_FEE_ENABLED` / `_PERCENTAGE` | `false` / `3.5` | Add a percentage to the charged amount. |
| `MONTYPAY_REQUEST_TIMEOUT` | `30` | HTTP timeout in seconds. |
| `MONTYPAY_REQ_TOKEN` | `true` | Ask for a reusable card token. |
| `MONTYPAY_SUCCESS_URL` / `_CANCEL_URL` / `_EXPIRY_URL` / `_ERROR_URL` | package routes | App paths for return URLs. |
| `MONTYPAY_DEFAULT_COUNTRY`, `_STATE`, `_CITY`, `_DISTRICT`, `_ADDRESS`, `_HOUSE_NUMBER`, `_ZIP`, `_PHONE` | empty | Fallback billing address. Empty values are not sent. |
| `MONTYPAY_VERIFY_CALLBACK_SIGNATURE` | `true` | Verify the webhook hash. Keep enabled. |
| `MONTYPAY_STORE_NOTIFICATIONS` | `true` | Store callbacks in `montypay_notifications` and ignore replays. |
| `MONTYPAY_PAYMENTS_ENABLED` / `_FROM_CALLBACKS` | `true` / `true` | Maintain `montypay_payments` records; also create one for callbacks about orders this package did not start. |
| `MONTYPAY_QUEUE_CALLBACKS`, `_QUEUE_CONNECTION`, `_QUEUE_NAME` | `false` | Fire callback events from a queued job. |
| `MONTYPAY_RECONCILE_OLDER_THAN` / `_MAX_AGE` / `_LIMIT` | `10` / `1440` / `100` | Defaults for `montypay:reconcile`. |

### Webhook URL

Register this with MontyPay (admin panel, or your account manager) as your notification URL:

```
POST https://your-app.test/montypay/callback
```

The route has no `web` middleware (no session or CSRF). Change `routes.prefix`, `routes.middleware` and `routes.callback_middleware` in `config/montypay.php` if needed. Customer returns land on `/montypay/success`, `/montypay/cancel` and `/montypay/decline`. The docs disagree on whether a per-request `notification_url` is accepted; if your account supports it, pass it as `options['extra']['notification_url']`.

## Environments

`MONTYPAY_ENV` picks the environment for the whole app, including which password the webhook verifies against. Sandbox moves no real money.

```php
MontyPay::environment('sandbox')->initCheckout($order, $customer, 'purchase');  // one call on the other environment
MontyPay::currentEnvironment();   // 'sandbox' | 'production'
MontyPay::isSandbox();
```

- MontyPay gives every account its own sandbox URL, so there is no sandbox default. A missing value fails **before** any request, naming the variables to set. It never falls back to the other environment.
- The un-prefixed `MONTYPAY_CHECKOUT_URL`, `MONTYPAY_MERCHANT_KEY` and `MONTYPAY_MERCHANT_PASSWORD` from earlier versions still work as the **production** fallback.
- Sandbox test card `4111 1111 1111 1111`, any CVV; the expiry picks the outcome: `01/38` success, `02/38` decline, `05/38` 3DS success, `06/38` 3DS fail. Recurring needs `01/38` for the initial payment.
- Your notification URL must be reachable from the internet, so use a tunnel when testing locally.

## Payments

### Creating a payment

```php
use AhmadChebbo\LaravelMontypay\Facades\MontyPay;

$session = MontyPay::initCheckout(
    orderDetails: ['number' => 'ORDER-123', 'amount' => '100.00', 'currency' => 'USD', 'description' => 'Purchase'],
    customerDetails: ['name' => 'John Doe', 'email' => 'customer@example.com'],
    operation: 'purchase',   // purchase | debit | transfer | credit
);

return redirect()->away($session['redirect_url']);
```

Optional arguments: `urls` (`success_url`, `cancel_url`, `expiry_url`, `error_url`), `options`, `billingAddress`. Options:

| Option | |
|---|---|
| `payable` | A model to link the payment record to (see [payment records](#payment-records)). |
| `card_token` | Pay with a stored token. |
| `recurring` | Start a subscription, see below. |
| `extra` | Any other documented session parameter: `auth` (`'Y'` = two-step DMS), `session_expiry`, `custom_data`, `channel_id`, ... The signed fields cannot be overridden. |
| `payment_methods`, `req_token`, `timeout`, `with_transaction_fee`, `transaction_fee_percentage` | Per-call overrides of the config. |

### Recurring payments and consent

```php
MontyPay::initCheckout($order, $customer, 'purchase', options: [
    'recurring' => [
        'schedule_id' => $scheduleId,          // created by MontyPay in the admin panel
        'start_date' => now()->addDays(14),    // optional: trial, first scheduled charge (date or 'Y-m-d')
        'amount' => '19.99',                   // optional: amount of the later charges
        'consent_required' => true,            // optional: show the "Customer consent" checkbox
    ],
]);
```

`'recurring' => true` alone starts a chain you charge by request. The docs require a `schedule_id` for `start_date`, `amount` and `consent_required`; the package throws a clear error before calling the API if it is missing.

With the consent checkbox the payer decides. **`recurring_token` in the callback is what tells the outcomes apart** (`type` is `sale` either way):

- Confirmed: `SubscriptionStarted` fires after `PaymentSettled`; the payment record gets `recurring_token`, `consent_id` and `consent_state = active`.
- Left unticked: it is a normal one-time purchase, no token; `consent_state` is stored as `ignored` (`$callback->ignoredConsent()`), if extended data is enabled in your Protocol Mapping.

Later charges: `$payment->chargeRecurring([...])`, `MontyPay::recurring(...)`, or the schedule runs them by itself (each produces a `recurring` callback). Cancelling a consent or a subscription happens in the admin panel and sends **no** callback, so poll if you need to know.

### Hosted Payment Fields

Card inputs on your own page, PCI scope handled by MontyPay. Supports `purchase` and `debit`.

```php
// 1. server: create the session (the payer's real IP avoids connector declines)
$session = MontyPay::createHostedSession(
    $order,
    $customer + ['ip' => $request->ip()],
    returnUrl: route('checkout.return'),     // required, used after 3DS
);
// ['token' => '...'], render the page with MontyPay::hostedFieldsScriptUrl() and $session['token']

// 2. browser: hostedFields.init({ sessionToken, fields: {...} }); on submit call collectIframesData()

// 3. server: process the payment
$result = MontyPay::payWithHostedFields($session['token'], [
    'name' => 'JOHN DOE',
    'browser_info' => MontyPay::browserInfo($request, ['screen_width' => '1920', ...]),
]);
// $result['result']: success | decline | redirect (send the payer to redirect_url) | waiting | undefined
```

Callbacks are the same as for Checkout. `browserInfo()` fills `accept_header` from the payer's request, which some connectors require.

### Post-payment operations

```php
MontyPay::capture($paymentId, '100.00');   // capture an `auth` payment
MontyPay::refund($paymentId, '25.00');     // full or partial refund
MontyPay::void($paymentId);                // release an authorisation
MontyPay::retry($paymentId);               // retry a soft-declined recurring payment

MontyPay::recurring($recurringInitTransId, $recurringToken, [
    'number' => 'SUB-2026-02', 'amount' => '9.99', 'description' => 'Monthly plan',
], ['schedule_id' => '...']);

MontyPay::cardCredit('PAYOUT-1', '25.00', 'USD', 'Affiliate payout', ['card_token' => $token]);
```

Failures throw `MontyPayException`; `getCode()` is the API's `error_code` and `$e->errors` the per-field errors. These calls only *send* the request: the outcome arrives as a callback.

### Status and return verification

```php
MontyPay::getTransactionStatusByPaymentId($paymentId);
MontyPay::getTransactionStatusByOrderId('ORDER-123', size: 5);   // the 5 most recent attempts
```

When "Return parameters" is enabled in your Protocol Mapping, MontyPay appends `payment_id`, `trans_id`, `order_id` and `hash` to `success_url` / `cancel_url`. The package checks that hash against the stored order (the URL carries no amount, currency or description) and **refuses a mismatch with a 403**. The return events carry `verified` (`true`, or `null` when it could not be checked). Use it yourself with `MontyPay::verifyReturn($request->query(), ['amount' => ..., 'currency' => ..., 'description' => ...])`. A verified return is still not proof of payment; use the callback.

## Handling payments

### Events

**The signed callback is the source of truth, not the customer return.** A payment is complete only when `type`, `status` and `order_status` agree (a `type=3ds, status=success` callback is not a completed payment). The package applies the docs' decision table and fires one typed event per verified callback:

| Event | Fires when | What to do |
|---|---|---|
| `PaymentSettled` | success + sale/capture/recurring/debit/transfer/credit + `settled` | Fulfil the order |
| `SubscriptionStarted` | a settled first payment that returned a `recurring_token` | Activate the subscription (also fires `PaymentSettled`) |
| `PaymentAuthorized` | success + `sale` + `pending` (DMS hold) | Capture or void; do not fulfil |
| `PaymentDeclined` | any `status=fail` | Inspect `$callback->type` and `->reason` |
| `PaymentRefunded` | success + `refund` | Mark refunded (`->isPartialRefund()`) |
| `PaymentVoided` | success + `void` | Mark voided |
| `ChargebackOpened` | `type=chargeback` | Open a dispute |
| `PaymentReversed` | `type=reversal` | Unblock retry / notify customer |
| `PaymentUndefined` | `status=undefined` | Do **not** fulfil; alert and reconcile |

`3ds`, `redirect`, `init` and `waiting` fire no outcome event. `CallbackReceived` fires for every new callback first. `PaymentSuccessful`, `PaymentCanceled` and `PaymentFailed` fire on the customer's browser return and are **not** proof of payment.

```php
Event::listen(function (PaymentSettled $event) {
    $cb = $event->callback;   // Data\MontyPayCallback: ->orderNumber ->orderAmount ->cardToken ->recurringToken ->customData ->raw ...

    Order::where('number', $cb->orderNumber)->first()?->markPaid($cb->id);
});
```

`type`, `status` and `order_status` are the enums `CallbackType`, `CallbackStatus` and `OrderStatus`.

### Payment records

One row per order attempt in `montypay_payments`, created with the session and updated from every verified callback (and from `montypay:reconcile`). Link it to your model:

```php
class Order extends Model { use \AhmadChebbo\LaravelMontypay\Concerns\HasMontyPayPayments; }

$session = $order->startMontyPayCheckout($orderDetails, $customerDetails);   // = initCheckout(..., options: ['payable' => $order])

$order->isMontyPayPaid();          // settled, or partially refunded
$order->isMontyPayAuthorized();    // DMS hold
$order->refundMontyPay();          // full; refundMontyPay('25.00') for partial
$order->captureMontyPay();
$order->voidMontyPay();
$order->montyPayPayments;          // every attempt
```

Each row is a `Models\Payment` with a `PaymentState` (`created`, `pending`, `failed`, `undefined`, `authorized`, `paid`, `partially_refunded`, `refunded`, `voided`, `reversed`, `chargeback`), plus `payment_id`, `amount` (exactly as sent, so hashes and refunds work for any currency), `currency`, `description`, `reason` and, for subscriptions, the encrypted `recurring_token`, `consent_id` and `consent_state` (`$payment->isSubscribed()`, `$payment->chargeRecurring([...])`).

- **State only moves forward.** A late `3ds` callback cannot turn a paid payment back into pending, and a failed refund or void never marks the payment itself as failed. A declined attempt followed by a successful retry (new `payment_id`, same order number) ends up `paid`.
- `capture()`, `refund()` and `void()` on a payment only send the request; the state changes when the callback arrives.
- Records are updated before your listeners run. `MONTYPAY_PAYMENTS_ENABLED=false` turns this off.

### Queued processing

MontyPay delivers each callback **once with no retries**, and blocks your URL for 15 minutes after 5 timeouts. To keep the webhook fast, set `MONTYPAY_QUEUE_CALLBACKS=true`: the callback is verified and stored, `200` goes out, and a queued job fires the events. Without the queue, listeners run inline and an exception in one is reported but never turns the webhook into a 500.

### Reconciling missed callbacks

```bash
php artisan montypay:reconcile            # --dry-run to preview; --older-than, --max-age, --limit
```

Needs `MONTYPAY_STORE_NOTIFICATIONS`. It (1) re-fires events for callbacks that were stored but never finished processing, and (2) polls the status API for payments still in flight (`prepare`, `3ds`, `redirect`, `waiting`, `undefined`, crypto `init`) that have been quiet for 10 minutes. Results go through the same pipeline, so the same typed event fires with `$callback->reconciled === true`. Held DMS authorisations are not polled. Schedule it:

```php
Schedule::command('montypay:reconcile')->everyFiveMinutes();   // routes/console.php
```

## Testing

```php
use AhmadChebbo\LaravelMontypay\Facades\MontyPay;

$montypay = MontyPay::fake();                       // no HTTP; hashing, payloads and payment records run for real

$order->startMontyPayCheckout($details, $customer);
$montypay->assertSessionCreated(fn ($payload) => $payload['order']['amount'] === '100.00');

// drive the webhook with a correctly signed callback
$this->post(route('montypay.callback'), MontyPay::signedCallback([
    'order_number' => $order->number, 'type' => 'sale', 'status' => 'success', 'order_status' => 'settled',
]))->assertOk();

expect($order->isMontyPayPaid())->toBeTrue();

$order->refundMontyPay('25.00');
$montypay->assertRefunded($paymentId, '25.00');
```

`MontyPay::fake(['refund' => new MontyPayException('nope')])` overrides a response by endpoint name (`session`, `token`, `card`, `capture`, `refund`, `void`, `retry`, `recurring`, `credit`, `status`); also `assertNotSent()`, `assertNothingSent()`, `assertCaptured()`, `assertVoided()`. `fake()` switches to a fake sandbox configuration so signed callbacks verify.

## License

MIT
