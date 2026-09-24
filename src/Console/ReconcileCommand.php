<?php

namespace AhmadChebbo\LaravelMontypay\Console;

use AhmadChebbo\LaravelMontypay\Enums\OrderStatus;
use AhmadChebbo\LaravelMontypay\Exceptions\MontyPayException;
use AhmadChebbo\LaravelMontypay\Models\PaymentNotification;
use AhmadChebbo\LaravelMontypay\Services\CallbackProcessor;
use AhmadChebbo\LaravelMontypay\Services\MontyPayService;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'montypay:reconcile
        {--older-than= : Only payments whose last callback is at least this many minutes old}
        {--max-age= : Ignore payments whose first callback is older than this many minutes}
        {--limit= : Maximum payments to poll in one run}
        {--dry-run : List what would be polled without calling the API or firing events}';

    protected $description = 'Recover missed callbacks: re-fire events that failed and poll the status API for payments stuck in a non-final state';

    /** Order statuses that, as the latest known state, mean the payment is still in flight. */
    private const IN_FLIGHT = ['prepare', '3ds', 'redirect'];

    public function handle(MontyPayService $montypay, CallbackProcessor $processor): int
    {
        if (! config('montypay.callback.store_notifications')) {
            $this->components->error('Reconciliation needs montypay.callback.store_notifications=true.');

            return self::FAILURE;
        }

        $olderThan = (int) ($this->option('older-than') ?? config('montypay.reconcile.older_than'));
        $maxAge = (int) ($this->option('max-age') ?? config('montypay.reconcile.max_age'));
        $limit = (int) ($this->option('limit') ?? config('montypay.reconcile.limit'));
        $dry = (bool) $this->option('dry-run');

        $this->refire($processor, $olderThan, $maxAge, $dry);
        $this->poll($montypay, $processor, $olderThan, $maxAge, $limit, $dry);

        return self::SUCCESS;
    }

    /**
     * Callbacks that were stored but whose events never completed (listener threw, worker died).
     */
    protected function refire(CallbackProcessor $processor, int $olderThan, int $maxAge, bool $dry): void
    {
        // With a queue, unprocessed rows are usually just a backlog: give the workers the full window.
        $grace = config('montypay.callback.queue') ? $olderThan : 2;

        $rows = PaymentNotification::whereNull('processed_at')
            ->where('created_at', '<=', now()->subMinutes($grace))
            ->where('created_at', '>=', now()->subMinutes($maxAge))
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $this->line(($dry ? '[dry-run] ' : '') . "re-firing {$row->type}/{$row->status} for {$row->payment_id}");

            if (! $dry) {
                $processor->dispatchEvents($row->payload, $row->id);
            }
        }

        $this->components->info($rows->count() . ' unprocessed callback(s) ' . ($dry ? 'found.' : 're-fired.'));
    }

    protected function poll(MontyPayService $montypay, CallbackProcessor $processor, int $olderThan, int $maxAge, int $limit, bool $dry): void
    {
        $candidates = PaymentNotification::where('created_at', '>=', now()->subMinutes($maxAge))
            ->distinct()
            ->pluck('payment_id');

        $stale = $candidates
            ->map(fn (string $id) => $this->staleLatest($id, $olderThan))
            ->filter()
            ->take($limit);

        $resolved = $unresolved = $failed = 0;

        foreach ($stale as $latest) {
            if ($dry) {
                $this->line("[dry-run] would poll {$latest->payment_id} (last: {$latest->type}/{$latest->status}/{$latest->order_status})");

                continue;
            }

            try {
                $status = $montypay->getTransactionStatusByPaymentId($latest->payment_id);
            } catch (MontyPayException $e) {
                $failed++;
                $this->components->warn("{$latest->payment_id}: {$e->getMessage()}");

                continue;
            }

            $payload = $this->toCallbackPayload($status, $latest);

            if ($payload === null) {
                $unresolved++;

                continue;
            }

            $processor->handle($payload) ? $resolved++ : $unresolved++;
        }

        $this->components->info($dry
            ? $stale->count() . ' stale payment(s) would be polled.'
            : "Polled {$stale->count()} stale payment(s): {$resolved} resolved, {$unresolved} still in flight, {$failed} failed."
        );
    }

    /**
     * The latest notification for a payment, if the payment is still unresolved and quiet for $olderThan minutes.
     */
    protected function staleLatest(string $paymentId, int $olderThan): ?PaymentNotification
    {
        $latest = PaymentNotification::where('payment_id', $paymentId)->latest('id')->first();

        if (! $latest || $latest->created_at->gt(now()->subMinutes($olderThan))) {
            return null;
        }

        // Anything final for this payment (settled, declined, refunded, ...) ends the chase.
        if (PaymentNotification::where('payment_id', $paymentId)
            ->whereIn('order_status', OrderStatus::terminalValues())
            ->exists()) {
            return null;
        }

        // A held DMS authorisation is a resting state: waiting on the merchant, not on MontyPay.
        if ($latest->type === 'sale' && $latest->order_status === 'pending' && $latest->status === 'success') {
            return null;
        }

        $inFlight = in_array($latest->order_status, self::IN_FLIGHT, true)
            || in_array($latest->status, ['waiting', 'undefined'], true)
            || $latest->type === 'init';

        return $inFlight ? $latest : null;
    }

    /**
     * Build a callback-shaped payload from a status-API response.
     * Returns null while the payment is still in progress or unrecognised.
     */
    protected function toCallbackPayload(array $status, PaymentNotification $latest): ?array
    {
        $orderStatus = match (strtolower((string) ($status['status'] ?? ''))) {
            'settled' => 'settled',
            'decline', 'declined' => 'decline',
            'refund', 'refunded' => 'refund',
            'void', 'voided' => 'void',
            'chargeback' => 'chargeback',
            'reversal' => 'reversal',
            'pending' => 'pending',
            default => null, // prepare / 3ds / redirect / unknown: still in flight
        };

        if ($orderStatus === null) {
            return null;
        }

        $type = match ($orderStatus) {
            'refund', 'void', 'chargeback', 'reversal' => $orderStatus,
            default => in_array($latest->type, ['sale', 'debit', 'credit', 'transfer', 'recurring', 'capture'], true)
                ? $latest->type
                : 'sale',
        };

        $order = $status['order'] ?? [];
        $known = $latest->payload ?? [];

        return array_filter([
            'id' => $latest->payment_id,
            'order_number' => $order['number'] ?? $known['order_number'] ?? $latest->order_id,
            'order_amount' => $order['amount'] ?? $known['order_amount'] ?? null,
            'order_currency' => $order['currency'] ?? $known['order_currency'] ?? null,
            'order_description' => $order['description'] ?? $known['order_description'] ?? null,
            'order_status' => $orderStatus,
            'type' => $type,
            'status' => $orderStatus === 'decline' ? 'fail' : 'success',
            'reason' => $status['reason'] ?? null,
            'card_token' => $status['card_token'] ?? null,
            'recurring_token' => $status['recurring_token'] ?? null,
            'schedule_id' => $status['schedule_id'] ?? null,
            'date' => $status['date'] ?? null,
            'reconciled' => true,
        ], fn ($value) => $value !== null);
    }
}
