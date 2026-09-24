<?php

namespace AhmadChebbo\LaravelMontypay\Testing;

use AhmadChebbo\LaravelMontypay\Services\MontyPayService;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * A MontyPayService that makes no HTTP calls. Everything else (hashing, payload building,
 * payment records) runs for real, so tests exercise the same code as production.
 *
 *     $montypay = MontyPay::fake();
 *     ... code under test ...
 *     $montypay->assertRefunded('payment-id', '25.00');
 *
 * Override a response by endpoint name: MontyPay::fake(['refund' => new MontyPayException('nope')]).
 * Names: session, token, card (hosted pay), capture, refund, void, retry, recurring, credit, status.
 */
class FakeMontyPayService extends MontyPayService
{
    /** @var list<array{path: string, payload: array, headers: array}> */
    public array $requests = [];

    public function __construct(protected array $responses = [])
    {
        parent::__construct('sandbox');
    }

    public function environment(string $environment): static
    {
        return $this;
    }

    protected function send(string $path, array $payload, ?int $timeout, string $action, array $headers = []): array
    {
        $this->credentials->assertConfigured();
        $this->requests[] = compact('path', 'payload', 'headers');

        $name = basename($path);
        $response = $this->responses[$path] ?? $this->responses[$name] ?? match ($name) {
            'session' => ['redirect_url' => 'https://montypay.test/pay/fake-session'],
            'token' => ['token' => 'fake-session-token'],
            'card' => ['result' => 'success', 'public_id' => '00000000-0000-0000-0000-000000000000'],
            'status' => ['status' => 'settled'],
            default => ['status' => 'ok'],
        };

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    /** Assert a request to an endpoint (e.g. '/payment/refund') was sent, optionally matching its payload. */
    public function assertSent(string $endpoint, ?Closure $callback = null): static
    {
        $matches = array_filter($this->requests, fn ($r) => str_ends_with($r['path'], $endpoint) && (! $callback || $callback($r['payload'], $r)));

        $sent = implode(', ', array_column($this->requests, 'path')) ?: 'nothing';

        Assert::assertNotEmpty($matches, "No request to [{$endpoint}] matched. Sent: {$sent}");

        return $this;
    }

    public function assertNotSent(string $endpoint): static
    {
        Assert::assertEmpty(
            array_filter($this->requests, fn ($r) => str_ends_with($r['path'], $endpoint)),
            "A request to [{$endpoint}] was sent."
        );

        return $this;
    }

    public function assertNothingSent(): static
    {
        Assert::assertEmpty($this->requests, 'Requests were sent: ' . implode(', ', array_column($this->requests, 'path')));

        return $this;
    }

    public function assertSessionCreated(?Closure $callback = null): static
    {
        return $this->assertSent('/api/v1/session', $callback);
    }

    public function assertCaptured(string $paymentId, ?string $amount = null): static
    {
        return $this->assertMoved('capture', $paymentId, $amount);
    }

    public function assertRefunded(string $paymentId, ?string $amount = null): static
    {
        return $this->assertMoved('refund', $paymentId, $amount);
    }

    public function assertVoided(string $paymentId): static
    {
        return $this->assertSent('/payment/void', fn ($p) => $p['payment_id'] === $paymentId);
    }

    private function assertMoved(string $operation, string $paymentId, ?string $amount): static
    {
        return $this->assertSent("/payment/{$operation}", fn ($p) => $p['payment_id'] === $paymentId && ($amount === null || $p['amount'] === $amount));
    }
}
