<?php

declare(strict_types=1);

namespace App\DataTransfer;

/**
 * Immutable representation of a row that failed validation/parsing.
 *
 * Captured with enough context (row number, raw content, reason) to make the
 * bad-data table useful for downstream investigation and re-processing.
 */
final readonly class BadVehicleRow
{
    /**
     * @param string|null $vin        VIN if it could be read, otherwise null.
     * @param int         $rowNumber  1-based row number within the source file.
     * @param string      $fileName   Source file the row came from.
     * @param string      $rawRowData JSON-encoded snapshot of the raw row.
     * @param string      $errorReason Human-readable validation failure summary.
     */
    public function __construct(
        public ?string $vin,
        public int $rowNumber,
        public string $fileName,
        public string $rawRowData,
        public string $errorReason,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'vin'          => $this->vin,
            'row_number'   => $this->rowNumber,
            'file_name'    => $this->fileName,
            'raw_row_data' => $this->rawRowData,
            'error_reason' => $this->errorReason,
        ];
    }
}
