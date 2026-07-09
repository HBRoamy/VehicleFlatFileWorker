<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Contracts\SecretsProviderInterface;
use App\Exceptions\SecretNotFoundException;

/**
 * Environment-backed secrets provider for development and tests.
 *
 * Secrets are seeded from an in-memory map (typically sourced from config/env
 * at bind time). {@see refresh()} re-reads that supplier so the same refresh
 * code path exercised in production can be exercised locally too.
 */
final class EnvSecretsProvider implements SecretsProviderInterface
{
    /** @var array<string, string> */
    private array $cache = [];

    /**
     * @param \Closure(): array<string, string> $supplier
     *        Returns the current full secret map when invoked.
     */
    public function __construct(
        private readonly \Closure $supplier,
    ) {
        $this->refresh();
    }

    public function getSecret(string $key): string
    {
        if (!array_key_exists($key, $this->cache)) {
            throw new SecretNotFoundException(sprintf('Unknown secret key: %s', $key));
        }

        return $this->cache[$key];
    }

    public function refresh(): int
    {
        $this->cache = ($this->supplier)();

        return count($this->cache);
    }
}
