<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransfer\RowOutcome;

/**
 * Abstraction over the strategy used to parse+validate a batch of raw rows.
 *
 * Production uses a single implementation, {@see \App\Services\Processing\AmpRecordProcessor},
 * which validates rows across an amphp/parallel worker pool bounded by the
 * configured max degree of parallelism. The test suite substitutes a
 * synchronous, in-process double (bound via the container) so tests are
 * deterministic and spawn no worker processes.
 *
 * Every implementation returns one {@see RowOutcome} per input row, preserving
 * the original order, and performs CPU-bound work only (parse + validate). The
 * database write is intentionally left to the caller so it can be committed as
 * a single atomic batch transaction.
 */
interface RecordProcessorInterface
{
    /**
     * @param list<array{0: int, 1: array<int, string>}> $indexedRows
     *        Each entry is [rowNumber, rawColumns].
     *
     * @return list<RowOutcome>
     */
    public function process(array $indexedRows, string $fileName): array;
}
