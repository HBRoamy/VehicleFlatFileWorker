<?php

declare(strict_types=1);

namespace App\Services\Processing;

use League\Csv\Reader;

/**
 * Thin wrapper around league/csv that turns raw CSV text into a list of
 * [rowNumber, columns] pairs, skipping the header row.
 *
 * Row numbers are 1-based and count the header as row 1, so the first data row
 * is row 2. This makes numbers reported in the bad-data table line up with what
 * a human sees when opening the file in a spreadsheet program.
 */
final class CsvVehicleParser
{
    /**
     * @return list<array{0: int, 1: array<int, string>}>
     *
     * @throws \League\Csv\Exception On malformed CSV structure.
     */
    public function parse(string $contents): array
    {
        $reader = Reader::createFromString($contents);
        $reader->setHeaderOffset(0);

        $rows = [];
        $rowNumber = 1; // header occupies row 1

        foreach ($reader->getRecords() as $record) {
            $rowNumber++;
            // Re-key to a positional array; league/csv yields header-keyed maps
            // when a header offset is set, but the validator works positionally.
            $rows[] = [$rowNumber, array_values($record)];
        }

        return $rows;
    }
}
