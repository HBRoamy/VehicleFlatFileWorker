<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LockAcquisitionException;
use App\Models\FileProcessingLock;
use App\Services\Locking\FileLockManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FileLockManagerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): FileLockManager
    {
        return $this->app->make(FileLockManager::class);
    }

    public function test_acquire_creates_a_processing_lock_owned_by_this_instance(): void
    {
        $lock = $this->manager()->acquire('vehicles.csv');

        self::assertSame(FileProcessingLock::STATUS_PROCESSING, $lock->status);
        self::assertSame('test-instance', $lock->locked_by);
        self::assertNotNull($lock->locked_at);
        $this->assertDatabaseHas('file_processing_locks', [
            'file_name' => 'vehicles.csv',
            'status'    => 'processing',
        ]);
    }

    public function test_acquire_fails_when_file_is_already_held_by_a_live_owner(): void
    {
        $this->manager()->acquire('vehicles.csv');

        $this->expectException(LockAcquisitionException::class);
        $this->manager()->acquire('vehicles.csv');
    }

    public function test_reclaims_a_stale_lock(): void
    {
        FileProcessingLock::create([
            'file_name'         => 'stale.csv',
            'locked_by'         => 'dead-instance',
            'status'            => FileProcessingLock::STATUS_PROCESSING,
            'locked_at'         => now()->subMinutes(31),
            'last_processed_at' => now()->subMinutes(31),
            'completed_at'      => null,
        ]);

        $lock = $this->manager()->acquire('stale.csv');

        self::assertSame('test-instance', $lock->locked_by);
        self::assertSame(FileProcessingLock::STATUS_PROCESSING, $lock->status);
        self::assertNull($lock->last_processed_at);
    }

    public function test_reclaims_a_failed_lock(): void
    {
        FileProcessingLock::create([
            'file_name' => 'failed.csv',
            'locked_by' => 'other',
            'status'    => FileProcessingLock::STATUS_FAILED,
            'locked_at' => now()->subMinute(),
        ]);

        $lock = $this->manager()->acquire('failed.csv');

        self::assertSame('test-instance', $lock->locked_by);
        self::assertSame(FileProcessingLock::STATUS_PROCESSING, $lock->status);
    }

    public function test_never_reclaims_a_completed_lock(): void
    {
        FileProcessingLock::create([
            'file_name'    => 'done.csv',
            'locked_by'    => 'other',
            'status'       => FileProcessingLock::STATUS_COMPLETED,
            'locked_at'    => now()->subMinutes(120),
            'completed_at' => now()->subMinutes(119),
        ]);

        $this->expectException(LockAcquisitionException::class);
        $this->manager()->acquire('done.csv');
    }

    public function test_mark_processed_advances_heartbeat_and_release_finalises(): void
    {
        $lock = $this->manager()->acquire('vehicles.csv');
        self::assertNull($lock->last_processed_at);

        $this->manager()->markProcessed($lock);
        self::assertNotNull($lock->fresh()->last_processed_at);

        $this->manager()->release($lock, success: true);
        $fresh = $lock->fresh();
        self::assertSame(FileProcessingLock::STATUS_COMPLETED, $fresh->status);
        self::assertNotNull($fresh->completed_at);

        $this->manager()->release($lock, success: false);
        self::assertSame(FileProcessingLock::STATUS_FAILED, $lock->fresh()->status);
    }
}
