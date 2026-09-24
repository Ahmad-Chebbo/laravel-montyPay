<?php

namespace AhmadChebbo\LaravelMontypay\Data;

/**
 * Billing address for a session: the given fields over the configured defaults.
 * Empty values are dropped, since the API treats every field as optional.
 */
class BillingAddress
{
    private array $fields;

    public function __construct(array $data = [])
    {
        $this->fields = $data + (array) config('montypay.default_billing_address', []);
    }

    public function toArray(): array
    {
        return array_filter($this->fields, fn ($value) => $value !== null && $value !== '');
    }
}
