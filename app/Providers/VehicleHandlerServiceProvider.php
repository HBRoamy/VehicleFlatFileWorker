<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\FileStorageServiceInterface;
use App\Contracts\QueueServiceInterface;
use App\Contracts\RecordProcessorInterface;
use App\Contracts\SecretsProviderInterface;
use App\Services\Locking\FileLockManager;
use App\Services\Processing\AmpRecordProcessor;
use App\Services\Processing\CsvVehicleParser;
use App\Services\Processing\VehicleFileProcessor;
use App\Services\Queue\LocalQueueService;
use App\Services\Queue\SqsQueueService;
use App\Services\Secrets\AwsSecretsProvider;
use App\Services\Secrets\EnvSecretsProvider;
use App\Services\Storage\LocalFileStorageService;
use App\Services\Storage\S3FileStorageService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires every abstraction to a concrete implementation chosen by config.
 *
 * The `vehicle.drivers.*` settings select between local/dev implementations
 * (no AWS, portable everywhere) and the production AWS-backed implementations.
 * Record processing is always performed by the amphp/parallel worker pool. This
 * single provider is the composition root of the application.
 */
final class VehicleHandlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindQueue();
        $this->bindStorage();
        $this->bindSecrets();
        $this->bindRecordProcessor();
        $this->bindLockManager();
        $this->bindFileProcessor();
    }

    private function bindQueue(): void
    {
        $this->app->singleton(QueueServiceInterface::class, function (Application $app): QueueServiceInterface {
            $driver = (string) config('vehicle.drivers.queue');

            if ($driver === 'sqs') {
                return new SqsQueueService(
                    client: new \Aws\Sqs\SqsClient($this->awsClientConfig()),
                    queueUrl: $app->make(SecretsProviderInterface::class)->getSecret('sqs_queue_url'),
                    logger: $app->make(LoggerInterface::class),
                );
            }

            return new LocalQueueService(
                queueDirectory: (string) config('vehicle.storage.local_queue_path'),
            );
        });
    }

    private function bindStorage(): void
    {
        $this->app->singleton(FileStorageServiceInterface::class, function (Application $app): FileStorageServiceInterface {
            $driver = (string) config('vehicle.drivers.storage');

            if ($driver === 's3') {
                return new S3FileStorageService(
                    client: new \Aws\S3\S3Client($this->awsClientConfig()),
                );
            }

            return new LocalFileStorageService(
                basePath: (string) config('vehicle.storage.local_incoming_path'),
            );
        });
    }

    private function bindSecrets(): void
    {
        $this->app->singleton(SecretsProviderInterface::class, function (Application $app): SecretsProviderInterface {
            $driver = (string) config('vehicle.drivers.secrets');

            if ($driver === 'aws') {
                return new AwsSecretsProvider(
                    client: new \Aws\SecretsManager\SecretsManagerClient($this->awsClientConfig()),
                    secretId: (string) config('vehicle.secrets.aws_secret_id'),
                    logger: $app->make(LoggerInterface::class),
                );
            }

            // Local: source the secret map from config each refresh.
            return new EnvSecretsProvider(
                supplier: static fn (): array => array_filter(
                    (array) config('vehicle.secrets.local', []),
                    static fn ($v): bool => $v !== null,
                ),
            );
        });
    }

    private function bindRecordProcessor(): void
    {
        // A single production strategy: amphp/parallel worker pool, capped at
        // the configured max degree of parallelism. It is registered as a
        // singleton so the worker pool is created once and reused across files
        // for the lifetime of the poller process.
        $this->app->singleton(RecordProcessorInterface::class, function (Application $app): RecordProcessorInterface {
            return new AmpRecordProcessor(
                maxDegreeOfParallelism: (int) config('vehicle.processing.max_degree_of_parallelism'),
            );
        });

        // Allow the concrete type to resolve to the same shared instance so the
        // poller command can call shutdown() on it directly.
        $this->app->singleton(AmpRecordProcessor::class, function (Application $app): AmpRecordProcessor {
            return $app->make(RecordProcessorInterface::class);
        });
    }

    private function bindLockManager(): void
    {
        $this->app->singleton(FileLockManager::class, function (Application $app): FileLockManager {
            return new FileLockManager(
                instanceId: (string) config('vehicle.instance_id'),
                staleTimeoutMinutes: (int) config('vehicle.locking.stale_lock_timeout_minutes'),
                logger: $app->make(LoggerInterface::class),
            );
        });
    }

    private function bindFileProcessor(): void
    {
        $this->app->singleton(VehicleFileProcessor::class, function (Application $app): VehicleFileProcessor {
            return new VehicleFileProcessor(
                storage: $app->make(FileStorageServiceInterface::class),
                parser: $app->make(CsvVehicleParser::class),
                recordProcessor: $app->make(RecordProcessorInterface::class),
                lockManager: $app->make(FileLockManager::class),
                batchSize: (int) config('vehicle.processing.max_degree_of_parallelism'),
                logger: $app->make(LoggerInterface::class),
            );
        });
    }

    /**
     * Shared AWS SDK client configuration. Region comes from config; the
     * default credential provider chain resolves credentials on EC2.
     *
     * @return array<string, mixed>
     */
    private function awsClientConfig(): array
    {
        return [
            'region'  => (string) config('vehicle.aws.region'),
            'version' => 'latest',
        ];
    }
}
