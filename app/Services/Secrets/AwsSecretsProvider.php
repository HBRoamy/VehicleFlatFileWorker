<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Contracts\SecretsProviderInterface;
use App\Exceptions\SecretNotFoundException;
use Aws\SecretsManager\SecretsManagerClient;
use Psr\Log\LoggerInterface;

/**
 * Production secrets provider backed by AWS Secrets Manager.
 *
 * A single secret (identified by $secretId) is expected to hold a JSON object
 * whose keys are the individual logical secrets (bucket name, DB connection
 * string, etc.). Values are cached in memory and only re-fetched when
 * {@see refresh()} is called - which the scheduled refresh service does on a
 * fixed interval.
 */
final class AwsSecretsProvider implements SecretsProviderInterface
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly SecretsManagerClient $client,
        private readonly string $secretId,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSecret(string $key): string
    {
        if ($this->cache === []) {
            $this->refresh();
        }

        if (!array_key_exists($key, $this->cache)) {
            throw new SecretNotFoundException(sprintf('Unknown secret key: %s', $key));
        }

        return $this->cache[$key];
    }

    public function refresh(): int
    {
        $result = $this->client->getSecretValue(['SecretId' => $this->secretId]);

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) $result->get('SecretString'), true) ?: [];

        $this->cache = $decoded;

        $this->logger->info('Refreshed secrets from AWS Secrets Manager', [
            'secret_id' => $this->secretId,
            'key_count' => count($this->cache),
        ]);

        return count($this->cache);
    }
}
