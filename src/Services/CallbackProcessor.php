<?php

namespace AhmadChebbo\LaravelMontypay\Services;

use AhmadChebbo\LaravelMontypay\Data\MontyPayCallback;
use AhmadChebbo\LaravelMontypay\Events\CallbackEvent;
use AhmadChebbo\LaravelMontypay\Events\CallbackReceived;
use AhmadChebbo\LaravelMontypay\Events\ChargebackOpened;
use AhmadChebbo\LaravelMontypay\Events\PaymentAuthorized;
use AhmadChebbo\LaravelMontypay\Events\PaymentDeclined;
use AhmadChebbo\LaravelMontypay\Events\PaymentRefunded;
use AhmadChebbo\LaravelMontypay\Events\PaymentReversed;
use AhmadChebbo\LaravelMontypay\Events\PaymentSettled;
use AhmadChebbo\LaravelMontypay\Events\PaymentUndefined;
use AhmadChebbo\LaravelMontypay\Events\PaymentVoided;
use AhmadChebbo\LaravelMontypay\Events\SubscriptionStarted;
use AhmadChebbo\LaravelMontypay\Jobs\ProcessCallback;
use AhmadChebbo\LaravelMontypay\Models\PaymentNotification;
use Illuminate\Support\Facades\Event;

/**
 * Turns a verified callback payload into stored state and events.
 *
 * Used by the webhook controller and by `montypay:reconcile`. Callers must
 * have authenticated the payload already (callback hash, or our own API call).
 */
class CallbackProcessor
{
    /**
     * Record the callback and fire its events (now, or on the queue).
     *
     * @return bool false when it was a replay (same id + type + status) and was ignored
     */
    public function handle(array $payload): bool
    {
        $notification = null;

        if (config('montypay.callback.store_notifications')) {
            $notification = PaymentNotification::firstOrCreate(
                [
                    'payment_id' => $payload['id'],
                    'type' => $payload['type'],
                    'status' => $payload['status'],
                ],
                [
                    'order_id' => $payload['order_number'] ?? null,
                    'order_status' => $payload['order_status'] ?? null,
                    'payload' => $payload,
                    'processed_at' => null,
                ]
            );

            if (! $notification->wasRecentlyCreated) {
                return false;
            }
        }

        if (config('montypay.callback.queue')) {
            ProcessCallback::dispatch($payload, $notification?->id)
                ->onConnection(config('montypay.callback.queue_connection'))
                ->onQueue(config('montypay.callback.queue_name'));

            return true;
        }

        // Inline: the webhook must still answer 200 fast and never fail because a
        // listener threw. The row stays unprocessed and `montypay:reconcile` retries it.
        try {
            $this->dispatchEvents($payload, $notification?->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * Fire CallbackReceived and the typed outcome event, then mark the stored row processed.
     */
    public function dispatchEvents(array $payload, ?int $notificationId = null): void
    {
        $callback = MontyPayCallback::fromArray($payload);

        // Update the payment record first so listeners see the new state. A bookkeeping
        // failure is reported but must never stop the events from firing.
        try {
            app(PaymentRecorder::class)->apply($callback);
        } catch (\Throwable $e) {
            report($e);
        }

        Event::dispatch(new CallbackReceived($payload));

        if ($event = $this->outcomeEvent($callback)) {
            Event::dispatch($event);
        }

        if ($callback->startedSubscription()) {
            Event::dispatch(new SubscriptionStarted($callback));
        }

        if ($notificationId) {
            PaymentNotification::whereKey($notificationId)->update(['processed_at' => now()]);
        }
    }

    /**
     * The docs' decision table: completion depends on type + status + order_status together.
     */
    public function outcomeEvent(MontyPayCallback $cb): ?CallbackEvent
    {
        return match (true) {
            $cb->isUndefined() => new PaymentUndefined($cb),
            $cb->isFailed() => new PaymentDeclined($cb),
            $cb->isSettled() => new PaymentSettled($cb),
            $cb->isAuthorized() => new PaymentAuthorized($cb),
            $cb->isRefunded() => new PaymentRefunded($cb),
            $cb->isVoided() => new PaymentVoided($cb),
            $cb->isChargeback() => new ChargebackOpened($cb),
            $cb->isReversal() => new PaymentReversed($cb),
            default => null, // 3ds / redirect / init / waiting: wait for the next callback
        };
    }
}
