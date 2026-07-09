<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Contracts\FileStorageServiceInterface;
use App\Contracts\RecordProcessorInterface;
use App\DataTransfer\QueueMessage;
use App\DataTransfer\RowOutcome;
use App\Models\BadVehicleData;
use App\Models\FileProcessingLock;
use App\Models\VehicleData;
use App\Services\Locking\FileLockManager;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * End-to-end processing of a single queued file.
 *
 * Flow:
 *   1. Acquire the row-level file lock for this instance (skip if held).
 *   2. Download the CSV via the storage abstraction.
 *   3. Parse into indexed rows.
 *   4. Process rows in chunks of `maxDegreeOfParallelism`. Each chunk is
 *      parsed+validated (possibly in parallel) and then committed as ONE
 *      atomic transaction containing both the good and bad rows for that
 *      chunk. `last_processed_at` is advanced after each committed chunk.
 *   5. Release the lock as completed (or failed on error).
 *
 * The batch boundary equals the degree of parallelism: exactly N vehicles are
 * validated together and persisted in a single transaction, mirroring the
 * source system's unit-of-work batching.
 */
final class VehicleFileProcessor
{
    public function __construct(
        private readonly FileStorageServiceInterface $storage,
        private readonly CsvVehicleParser $parser,
        private readonly RecordProcessorInterface $recordProcessor,
        private readonly FileLockManager $lockManager,
        private readonly int $batchSize,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{processed: int, good: int, bad: int}
     */
    public function handle(QueueMessage $message): array
    {
        $fileName = $message->fileName;

        // Throws LockAcquisitionException if already held; caller decides how
        // to treat that (skip + ack).
        $lock = $this->lockManager->acquire($fileName);

        $this->logger->info('Acquired file lock', [
            'file_name' => $fileName,
            'lock_id'   => $lock->id,
        ]);

        $good = 0;
        $bad = 0;

        try {
            $contents = $this->storage->getFileContents($message->bucket, $message->objectKey());
            $rows = $this->parser->parse($contents);

            foreach (array_chunk($rows, $this->batchSize) as $chunk) {
                $outcomes = $this->recordProcessor->process($chunk, $fileName);
                [$chunkGood, $chunkBad] = $this->commitChunk($outcomes);

                $good += $chunkGood;
                $bad += $chunkBad;

                // Heartbeat: record progress so a long file shows liveness and
                // stale-lock detection does not reclaim an actively-running job.
                $this->lockManager->markProcessed($lock);
            }

            $this->lockManager->release($lock, success: true);

            $this->logger->info('File processed successfully', [
                'file_name' => $fileName,
                'good'      => $good,
                'bad'       => $bad,
            ]);
        } catch (Throwable $e) {
            $this->lockManager->release($lock, success: false);

            $this->logger->error('File processing failed', [
                'file_name' => $fileName,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'processed' => $good + $bad,
            'good'      => $good,
            'bad'       => $bad,
        ];
    }

    /**
     * Persist one chunk's outcomes inside a single transaction.
     *
     * @param list<RowOutcome> $outcomes
     *
     * @return array{0: int, 1: int} [goodCount, badCount]
     */
    private function commitChunk(array $outcomes): array
    {
        $now = now();
        $goodRows = [];
        $badRows = [];

        foreach ($outcomes as $outcome) {
            if ($outcome->isValid && $outcome->record !== null) {
                $row = $outcome->record->toDatabaseRow();
                $row['last_updated'] = $now;
                $goodRows[] = $row;
            } elseif ($outcome->badRow !== null) {
                $row = $outcome->badRow->toDatabaseRow();
                $row['created_at'] = $now;
                $badRows[] = $row;
            }
        }

        DB::transaction(function () use ($goodRows, $badRows): void {
            if ($goodRows !== []) {
                // Upsert on VIN so re-processing a file updates existing rows
                // rather than failing on the unique key.
                VehicleData::query()->upsert(
                    $goodRows,
                    uniqueBy: ['vin'],
                    // Every mutable column is refreshed when a later file carries
                    // an updated record for an existing VIN. The VIN itself and the
                    // fields derived from it (wmi, vin_region, vin_country) are
                    // functionally determined by the conflict key, so they are
                    // intentionally excluded.
                    update: [
                        'model', 'model_year', 'trim', 'body_style',
                        'current_price_usd', 'msrp_usd', 'carfax_certified',
                        'pincode', 'is_new', 'mileage', 'previous_owners',
                        'exterior_color', 'interior_color', 'engine_type',
                        'transmission', 'fuel_efficiency_mpg', 'manufacture_date',
                        'registration_date', 'features', 'last_updated',
                    ],
                );
            }

            if ($badRows !== []) {
                BadVehicleData::query()->insert($badRows);
            }
        });

        return [count($goodRows), count($badRows)];
    }
}
