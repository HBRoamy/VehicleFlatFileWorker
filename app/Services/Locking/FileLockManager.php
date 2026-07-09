<?php

declare(strict_types=1);

namespace App\Services\Locking;

use App\Exceptions\LockAcquisitionException;
use App\Models\FileProcessingLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Manages the row-level distributed file lock in `file_processing_locks`.
 *
 * The lock row is keyed by `file_name` (unique). Ownership is recorded in
 * `locked_by` using the EC2 instance id. The application is fully responsible
 * for setting, honouring and clearing the lock:
 *
 *  - {@see acquire()} claims a file for this instance, refusing if another
 *    live instance already holds it. Stale locks (owner presumed dead) are
 *    reclaimed automatically.
 *  - {@see markProcessed()} advances `last_processed_at` as work proceeds.
 *  - {@see release()} finalises the lock as completed or failed.
 */
final class FileLockManager
{
    public function __construct(
        private readonly string $instanceId,
        private readonly int $staleTimeoutMinutes,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Attempt to acquire the lock for $fileName on behalf of this instance.
     *
     * Uses a transaction with a pessimistic row lock so that concurrent
     * pollers on different instances cannot both claim the same file. A unique
     * constraint on `file_name` is the ultimate backstop against duplicates.
     *
     * @throws LockAcquisitionException When the file is already held by a live instance.
     */
    public function acquire(string $fileName): FileProcessingLock
    {
        try {
            return DB::transaction(function () use ($fileName): FileProcessingLock {
                /** @var FileProcessingLock|null $existing */
                $existing = FileProcessingLock::query()
                    ->where('file_name', $fileName)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    return $this->createLockRow($fileName);
                }

                if ($this->isReclaimable($existing)) {
                    $this->logger->warning('Reclaiming file lock', [
                        'file_name'      => $fileName,
                        'previous_owner' => $existing->locked_by,
                        'previous_status'=> $existing->status,
                    ]);

                    $existing->update([
                        'locked_by'         => $this->instanceId,
                        'status'            => FileProcessingLock::STATUS_PROCESSING,
                        'locked_at'         => now(),
                        'last_processed_at' => null,
                        'completed_at'      => null,
                    ]);

                    return $existing;
                }

                throw new LockAcquisitionException(sprintf(
                    'File "%s" is already locked by "%s" (status: %s).',
                    $fileName,
                    (string) $existing->locked_by,
                    $existing->status,
                ));
            });
        } catch (QueryException $e) {
            // A unique-constraint violation means another instance inserted the
            // lock row in the race window; treat it as "already locked".
            if ($this->isUniqueViolation($e)) {
                throw new LockAcquisitionException(sprintf(
                    'File "%s" was locked concurrently by another instance.',
                    $fileName,
                ), previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Update the heartbeat/progress marker for an in-flight lock.
     */
    public function markProcessed(FileProcessingLock $lock): void
    {
        $lock->update(['last_processed_at' => now()]);
    }

    /**
     * Finalise the lock. On success the row is retained with a completed
     * status (an audit trail and a guard against reprocessing); on failure it
     * is marked failed so it can be retried or investigated.
     */
    public function release(FileProcessingLock $lock, bool $success): void
    {
        $lock->update([
            'status'       => $success
                ? FileProcessingLock::STATUS_COMPLETED
                : FileProcessingLock::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    private function createLockRow(string $fileName): FileProcessingLock
    {
        return FileProcessingLock::create([
            'file_name'         => $fileName,
            'locked_by'         => $this->instanceId,
            'status'            => FileProcessingLock::STATUS_PROCESSING,
            'locked_at'         => now(),
            'last_processed_at' => null,
            'completed_at'      => null,
        ]);
    }

    /**
     * A lock can be reclaimed if it previously failed, or if it is still marked
     * as processing but has exceeded the stale timeout (owner presumed dead).
     * A completed lock is never reclaimable - the file has already been done.
     */
    private function isReclaimable(FileProcessingLock $lock): bool
    {
        if ($lock->status === FileProcessingLock::STATUS_COMPLETED) {
            return false;
        }

        if ($lock->status === FileProcessingLock::STATUS_FAILED) {
            return true;
        }

        return $lock->locked_at !== null
            && $lock->locked_at->lt(now()->subMinutes($this->staleTimeoutMinutes));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23505 = unique_violation (PostgreSQL); 23000 for SQLite/MySQL.
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        return in_array($sqlState, ['23505', '23000'], true);
    }
}
