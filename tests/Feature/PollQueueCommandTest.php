<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileProcessingLock;
use App\Models\VehicleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PollQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_once_processes_a_queued_file_and_acks_the_message(): void
    {
        $message = $this->queue->push('vehicles_valid.csv');

        $this->artisan('vehicle:poll', ['--once' => true])
            ->assertExitCode(0);

        self::assertSame(10, VehicleData::count());
        self::assertSame([$message->receiptHandle], $this->queue->acknowledged());
        self::assertSame(0, $this->queue->pendingCount());
        self::assertSame(0, $this->queue->inFlightCount());

        $lock = FileProcessingLock::where('file_name', 'vehicles_valid.csv')->firstOrFail();
        self::assertSame(FileProcessingLock::STATUS_COMPLETED, $lock->status);
    }

    public function test_poll_once_with_empty_queue_exits_cleanly(): void
    {
        $this->artisan('vehicle:poll', ['--once' => true])
            ->assertExitCode(0);

        self::assertSame(0, VehicleData::count());
    }

    public function test_locked_file_is_skipped_and_acked_without_error(): void
    {
        // Pre-lock the file under a different, still-live instance so this
        // poller must skip it.
        FileProcessingLock::create([
            'file_name' => 'vehicles_valid.csv',
            'locked_by' => 'another-instance',
            'status'    => FileProcessingLock::STATUS_PROCESSING,
            'locked_at' => now(),
        ]);

        $message = $this->queue->push('vehicles_valid.csv');

        $this->artisan('vehicle:poll', ['--once' => true])
            ->assertExitCode(0);

        // Skipped: nothing persisted, but the message is still acknowledged.
        self::assertSame(0, VehicleData::count());
        self::assertSame([$message->receiptHandle], $this->queue->acknowledged());
    }
}
