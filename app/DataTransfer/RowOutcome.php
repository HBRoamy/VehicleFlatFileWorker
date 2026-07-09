<?php

declare(strict_types=1);

namespace App\DataTransfer;

/**
 * The outcome of attempting to parse+validate a single CSV row.
 *
 * Exactly one of {@see $record} or {@see $badRow} is populated. This is the
 * value returned by each (potentially parallel) parse worker; it is plain and
 * serializable so it can cross a process boundary intact.
 */
final readonly class RowOutcome
{
    private function __construct(
        public bool $isValid,
        public ?VehicleRecord $record,
        public ?BadVehicleRow $badRow,
    ) {
    }

    public static function valid(VehicleRecord $record): self
    {
        return new self(true, $record, null);
    }

    public static function bad(BadVehicleRow $badRow): self
    {
        return new self(false, null, $badRow);
    }
}
