<?php

namespace AhmadChebbo\LaravelMontypay\Support;

use AhmadChebbo\LaravelMontypay\Exceptions\MontyPayException;

/**
 * The URL, merchant key and password for one environment.
 *
 * Sandbox and production are separate credentials: the sandbox uses the merchant
 * *Test key* and its own password, and MontyPay gives every account its own
 * sandbox base URL, so there is no built-in sandbox default.
 */
final class Credentials
{
    public const SANDBOX = 'sandbox';
    public const PRODUCTION = 'production';

    public function __construct(
        public readonly string $environment,
        public readonly ?string $checkoutUrl,
        public readonly ?string $merchantKey,
        public readonly ?string $password,
    ) {
    }

    /**
     * Resolve the credentials for $environment (default: `montypay.environment`).
     *
     * @throws MontyPayException for an unknown environment name
     */
    public static function for(?string $environment = null): self
    {
        $environment = strtolower($environment ?? (string) config('montypay.environment', self::PRODUCTION));

        if (! in_array($environment, [self::SANDBOX, self::PRODUCTION], true)) {
            throw new MontyPayException("Unknown MontyPay environment [{$environment}]. Use 'sandbox' or 'production'.");
        }

        $config = (array) config("montypay.environments.{$environment}", []);

        // Installs from before the switcher published top-level keys; those meant production.
        $legacy = $environment === self::PRODUCTION
            ? [
                'checkout_url' => config('montypay.checkout_url'),
                'merchant_key' => config('montypay.merchant_key'),
                'merchant_password' => config('montypay.merchant_password'),
            ]
            : [];

        $value = fn (string $key) => ($config[$key] ?? null) ?: ($legacy[$key] ?? null) ?: null;

        return new self(
            $environment,
            self::trimUrl($value('checkout_url')),
            $value('merchant_key'),
            $value('merchant_password'),
        );
    }

    public function isSandbox(): bool
    {
        return $this->environment === self::SANDBOX;
    }

    /**
     * @throws MontyPayException naming exactly which environment variables are missing
     */
    public function assertConfigured(): void
    {
        $prefix = 'MONTYPAY_' . strtoupper($this->environment) . '_';

        $missing = array_keys(array_filter([
            $prefix . 'URL' => ! $this->checkoutUrl,
            $prefix . 'MERCHANT_KEY' => ! $this->merchantKey,
            $prefix . 'MERCHANT_PASSWORD' => ! $this->password,
        ]));

        if ($missing !== []) {
            throw new MontyPayException(
                "MontyPay [{$this->environment}] credentials are incomplete. Set: " . implode(', ', $missing) . '.'
            );
        }
    }

    private static function trimUrl(?string $url): ?string
    {
        return $url === null ? null : rtrim($url, '/');
    }
}
