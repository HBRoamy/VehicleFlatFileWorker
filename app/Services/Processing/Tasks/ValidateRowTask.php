<?php

declare(strict_types=1);

namespace App\Services\Processing\Tasks;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use App\DataTransfer\BadVehicleRow;
use App\DataTransfer\RowOutcome;
use App\Validation\VehicleRecordValidator;

/**
 * An amphp {@see Task} that validates a single CSV row inside a worker.
 *
 * The task is serialized in the parent and unserialized in the worker, so it
 * carries only plain, serializable state (row number, raw columns, file name).
 * It constructs its own validator inside {@see run()} - the validator is
 * stateless and Composer-autoloadable, so it is available in the worker
 * process. The returned {@see RowOutcome} is likewise fully serializable and
 * is sent back to the parent, which performs the batched database write.
 *
 * @implements Task<RowOutcome, never, never>
 */
final class ValidateRowTask implements Task
{
    /**
     * @param array<int, string> $columns
     */
    public function __construct(
        private readonly int $rowNumber,
        private readonly array $columns,
        private readonly string $fileName,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): RowOutcome
    {
        $validator = new VehicleRecordValidator();
        [$record, $errors] = $validator->validate($this->columns);

        if ($record !== null) {
            return RowOutcome::valid($record);
        }

        $vin = $this->columns[0] ?? null;
        $vin = is_string($vin) && $vin !== '' ? strtoupper(trim($vin)) : null;

        return RowOutcome::bad(new BadVehicleRow(
            vin: $vin,
            rowNumber: $this->rowNumber,
            fileName: $this->fileName,
            rawRowData: json_encode($this->columns, JSON_THROW_ON_ERROR),
            errorReason: implode(' ', $errors),
        ));
    }
}
