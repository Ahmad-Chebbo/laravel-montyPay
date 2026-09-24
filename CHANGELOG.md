# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-09-25

Brings the package in line with the current MontyPay documentation. It changes behaviour for anyone on 1.0 (mostly config and the webhook route), but 1.0 could not authenticate with MontyPay at all because its hash signatures were wrong, so it is released as a minor version. See [Upgrading from 1.0](README.md#upgrading-from-10).

### Added

- **`php artisan montypay:install`**: publishes the config and migration, adds missing `MONTYPAY_*` keys to `.env` and `.env.example` (never overwriting values; a file with existing live credentials is set to `production`), offers to run `migrate`, and prints the webhook URL. Options: `--views`, `--migrate`, `--force`, `--no-env`.
- **Environment switcher**: `MONTYPAY_ENV` (`sandbox` | `production`) with separate URL, merchant key and password per environment; `MontyPay::environment('sandbox')`, `currentEnvironment()` and `isSandbox()`. A missing value fails before any request and names the variables to set; sandbox never falls back to production. The old un-prefixed variables still work as the production fallback.
- **Post-payment operations**: `capture()`, `refund()`, `void()`, `retry()`, `recurring()` and `cardCredit()` (payout to a card).
- **Checkout session options**: `options['extra']` passes any documented session parameter (`auth` for two-step DMS, `session_expiry`, `custom_data`, ...); `options['payable']` links the payment to a model; `card_token` is sent as a top-level parameter.
- **Recurring and consent**: `options['recurring']` (`schedule_id`, `start_date`, `amount`, `consent_required`) with the docs' `schedule_id` requirements checked before the API call; `SubscriptionStarted` event; `MontyPayCallback::startedSubscription()` and `ignoredConsent()`; consent fields stored on the payment record.
- **Hosted Payment Fields**: `createHostedSession()`, `payWithHostedFields()` (purchase and debit), `browserInfo()` and `hostedFieldsScriptUrl()`.
- **Customer-return verification**: `verifyReturn()` checks the hash MontyPay appends to `success_url` / `cancel_url` against the stored order; the return pages refuse a mismatch with a 403, and the return events carry `verified`.
- **Status lookup by order**: `getTransactionStatusByOrderId(..., size: 1..100)` for the most recent attempts.
- **Typed callback handling**: enums `CallbackType`, `CallbackStatus`, `OrderStatus` and `PaymentState`; the `MontyPayCallback` data object with the docs' decision logic; outcome events `PaymentSettled`, `PaymentAuthorized`, `PaymentDeclined`, `PaymentRefunded`, `PaymentVoided`, `ChargebackOpened`, `PaymentReversed`, `PaymentUndefined` and `SubscriptionStarted`. `CallbackReceived` still fires for every new callback.
- **Queued callback processing**: `MONTYPAY_QUEUE_CALLBACKS` answers the webhook immediately and fires listeners from a `ProcessCallback` job (connection and queue configurable).
- **`php artisan montypay:reconcile`**: re-fires events for stored callbacks that never finished processing and polls the status API for payments stuck in flight, feeding results through the same pipeline (`$callback->reconciled`). Options: `--dry-run`, `--older-than`, `--max-age`, `--limit`.
- **Payment records**: a `montypay_payments` table and `Models\Payment` (state, amount, currency, description, reason, encrypted recurring and card tokens, consent), kept up to date from callbacks with forward-only state transitions; `HasMontyPayPayments` trait (`startMontyPayCheckout()`, `isMontyPayPaid()`, `refundMontyPay()`, `captureMontyPay()`, `voidMontyPay()`); `Payment::capture()`, `refund()`, `void()`, `chargeRecurring()` and `isSubscribed()`.
- **Test helpers**: `MontyPay::fake()` (no HTTP; hashing, payloads and records run for real) with `assertSent()`, `assertSessionCreated()`, `assertRefunded()`, `assertCaptured()`, `assertVoided()`, `assertNotSent()` and `assertNothingSent()`; `MontyPay::signedCallback()` for driving the webhook route.
- `MONTYPAY_HASH_ALGORITHM` (`md5` | `sha256`) for accounts with "Use SHA256 encryption algorithm for hash" enabled.
- Published asset tags `montypay-config`, `montypay-migrations` and `montypay-views`.

### Changed

- **Breaking:** hash signatures rebuilt to match the docs: values are concatenated with no separators, `mb_strtoupper` for requests and `strtoupper` for callbacks and returns. The 1.0 signatures were rejected by MontyPay.
- **Breaking:** the webhook is `POST /montypay/callback`, reads `id` and `hash` (not `payment_id` and `signature`), compares with `hash_equals`, and is registered without the `web` middleware (no CSRF/session; see `routes.callback_middleware`). Callbacks are stored once per `id + type + status` and replays are ignored.
- **Breaking:** the transaction fee is now off by default (was on at 3.5%), and the fake default billing address (Los Angeles) is gone: empty billing fields are no longer sent.
- **Breaking:** config: removed `events`, `urls.decline`, `urls.callback` and the unused `callback.queue_notifications`; added `environment`, `environments`, `hash_algorithm`, `payments`, `reconcile`, `callback.queue*`, `routes.callback_middleware`, `urls.expiry` and `urls.error`. Return URLs default to the package routes.
- **Breaking:** the notifications table is `montypay_notifications` (with `type` and `order_status` columns and a unique index) and its migration is timestamped. The facade is `AhmadChebbo\LaravelMontypay\Facades\MontyPay`.
- **Breaking:** `PaymentSuccessful`, `PaymentCanceled` and `PaymentFailed` (customer-return events) extend `ReturnEvent`; their payload is the return query plus `verified`, and `PaymentSuccessful::getPaymentNotification()` is removed. They are not proof of payment.
- The session request sends `error_url` instead of the unsupported `decline_url` and `callback_url`, and hashes and sends the amount as the same string.
- API errors now surface MontyPay's own `error_code`, message and per-field `errors` (`MontyPayException::$errors`) instead of a generic wrapper message.
- `composer.json`: requires PHP `^8.2` and the `illuminate/*` packages (10, 11, 12, 13); `minimum-stability` is `stable`.
- The README is rewritten and reorganised.

### Fixed

- Callback verification used the password from a non-existent config key (`services.monty_pay.password`), so it never verified.
- The callback route threw "Class not found" (missing `MontyPayCallBackRequest` import) and stored non-existent `payment_id` / `order_id` fields.
- The service provider read non-existent config keys (`route_prefix`, `middleware`), registered event listeners that did not exist, and used the wrong `Route` class.
- The facade alias pointed at a class that did not exist; the `cancel` view was missing and the views were published from the wrong path.
- The customer-return pages tried to verify a signature the return URL cannot carry, and fired success events on an unverified redirect.
- Implicitly nullable parameters deprecated in PHP 8.4.

### Security

- Constant-time hash comparison, a required signature on callbacks (verification stays on by default), and refusal of a mismatching return hash.
- Recurring and card tokens are stored encrypted and hidden from serialisation.
- The session's signed and identity fields (`merchant_key`, `hash`, `order`) cannot be overridden through `options['extra']`.

### Removed

- Not part of this package: schedule create/update/pause/delete. It is not a Checkout API feature (the docs say schedules are set up in the admin panel); it exists only in the separate S2S CARD protocol.

## [1.0.0]

### Added

- Initial release: Checkout session creation, transaction status lookup by payment id and order id, callback and customer-return routes with signature verification, stored notifications, and configurable transaction fee, default billing address and return URLs.

[Unreleased]: https://github.com/ahmad-chebbo/laravel-montypay/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ahmad-chebbo/laravel-montypay/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ahmad-chebbo/laravel-montypay/releases/tag/v1.0.0
