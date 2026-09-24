<?php

namespace AhmadChebbo\LaravelMontypay\Jobs;

use AhmadChebbo\LaravelMontypay\Services\CallbackProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fires the events for an already-verified, already-stored callback.
 * Dispatched when `montypay.callback.queue` is enabled.
 */
class ProcessCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public array $payload, public ?int $notificationId = null)
    {
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(CallbackProcessor $processor): void
    {
        $processor->dispatchEvents($this->payload, $this->notificationId);
    }
}
