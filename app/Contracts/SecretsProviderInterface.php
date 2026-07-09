<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Abstraction over the secrets backend (e.g. AWS Secrets Manager).
 *
 * Production binds this to an AWS-backed implementation; tests/local runs bind
 * it to an environment-variable-backed implementation.
 */
interface SecretsProviderInterface
{
    /**
     * Retrieve a single secret value by logical key.
     *
     * @throws \App\Exceptions\SecretNotFoundException When the key is unknown.
     */
    public function getSecret(string $key): string;

    /**
     * Force a reload of all secrets from the backing store. Returns the number
     * of secrets that were (re)loaded. Implementations should cache values
     * between refreshes so {@see getSecret()} stays cheap.
     */
    public function refresh(): int;
}
