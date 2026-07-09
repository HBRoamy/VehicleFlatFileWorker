<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\QueueServiceInterface;
use App\Contracts\RecordProcessorInterface;
use App\Services\Processing\AmpRecordProcessor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\InMemoryQueueService;
use Tests\Support\SyncRecordProcessor;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected InMemoryQueueService $queue;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every test-only choice here: swap the external-service
        // interfaces for deterministic in-process doubles. Production bindings
        // (SQS/S3/AWS/amphp) and the app code itself are untouched.
        $this->queue = new InMemoryQueueService();
        $this->app->instance(QueueServiceInterface::class, $this->queue);

        // Validation runs synchronously in-process (no amphp worker pool).
        $this->app->bind(RecordProcessorInterface::class, static fn (): SyncRecordProcessor => new SyncRecordProcessor());

        // PollQueueCommand type-hints the concrete AmpRecordProcessor purely to
        // call shutdown() on teardown. Keep it a real (idle) instance so the
        // type hint is satisfied; its worker pool is never started because all
        // processing goes through the synchronous RecordProcessorInterface.
        $this->app->bind(AmpRecordProcessor::class, static fn (): AmpRecordProcessor => new AmpRecordProcessor(
            maxDegreeOfParallelism: (int) config('vehicle.processing.max_degree_of_parallelism'),
        ));
    }
}
