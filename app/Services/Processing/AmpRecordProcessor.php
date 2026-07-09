<?php

declare(strict_types=1);

namespace App\Services\Processing;

use Amp\Future;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\WorkerPool;
use App\Contracts\RecordProcessorInterface;
use App\DataTransfer\RowOutcome;
use App\Services\Processing\Tasks\ValidateRowTask;

/**
 * Parallel record processor backed by amphp/parallel.
 *
 * A single {@see WorkerPool} is created lazily and reused for the lifetime of
 * the process (the long-running poller), capped at the configured max degree
 * of parallelism. Each row is submitted as a {@see ValidateRowTask}; the pool
 * runs at most N tasks concurrently and queues the rest.
 *
 * Workers perform CPU-bound work only (parse + validate) and return plain,
 * serializable {@see RowOutcome} objects. A worker process cannot participate
 * in the parent's database transaction, so - by design - all persistence is
 * left to the caller, which writes each batch atomically.
 *
 * Uses process-based workers (the amphp default), which require no PHP
 * extensions and run on Linux, macOS and Windows alike. Installing ext-parallel
 * on a ZTS build would transparently upgrade the workers to native threads.
 */
final class AmpRecordProcessor implements RecordProcessorInterface
{
    private ?WorkerPool $pool = null;

    public function __construct(
        private readonly int $maxDegreeOfParallelism,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function process(array $indexedRows, string $fileName): array
    {
        if ($indexedRows === []) {
            return [];
        }

        $pool = $this->pool();

        // Submit every row; the pool enforces the concurrency cap internally.
        $executions = [];
        foreach ($indexedRows as [$rowNumber, $columns]) {
            $executions[$rowNumber] = $pool->submit(
                new ValidateRowTask($rowNumber, $columns, $fileName),
            );
        }

        // Await all results. Future\await() preserves the array keys, so the
        // outcomes come back mapped by row number regardless of completion
        // order; we then restore the original row ordering.
        $outcomes = Future\await(array_map(
            static fn (Execution $execution): Future => $execution->getFuture(),
            $executions,
        ));

        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * Cleanly shut the pool down. Called from the owning command when the
     * daemon stops so worker processes are not orphaned.
     */
    public function shutdown(): void
    {
        $this->pool?->shutdown();
        $this->pool = null;
    }

    private function pool(): WorkerPool
    {
        return $this->pool ??= new ContextWorkerPool($this->maxDegreeOfParallelism);
    }
}
