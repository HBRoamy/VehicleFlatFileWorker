<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\RecordProcessorInterface;
use App\DataTransfer\BadVehicleRow;
use App\DataTransfer\RowOutcome;
use App\Validation\VehicleRecordValidator;

/**
 * Test-only, in-process implementation of {@see RecordProcessorInterface}.
 *
 * Runs the real {@see VehicleRecordValidator} synchronously in the current
 * process instead of dispatching to an amphp worker pool. It mirrors the
 * behaviour of {@see \App\Services\Processing\Tasks\ValidateRowTask} exactly
 * (same VIN extraction and error-joining for rejected rows) so that tests
 * exercise the genuine validation logic while remaining deterministic and
 * free of worker processes. Bound only from the test suite; never used in
 * production.
 */
final class SyncRecordProcessor implements RecordProcessorInterface
{
    public function __construct(
        private readonly VehicleRecordValidator $validator = new VehicleRecordValidator(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function process(array $indexedRows, string $fileName): array
    {
        $outcomes = [];

        foreach ($indexedRows as [$rowNumber, $columns]) {
            [$record, $errors] = $this->validator->validate($columns);

            if ($record !== null) {
                $outcomes[] = RowOutcome::valid($record);

                continue;
            }

            $vin = $columns[0] ?? null;
            $vin = is_string($vin) && $vin !== '' ? strtoupper(trim($vin)) : null;

            $outcomes[] = RowOutcome::bad(new BadVehicleRow(
                vin: $vin,
                rowNumber: $rowNumber,
                fileName: $fileName,
                rawRowData: json_encode($columns, JSON_THROW_ON_ERROR),
                errorReason: implode(' ', $errors),
            ));
        }

        return $outcomes;
    }
}
