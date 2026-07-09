<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Processing\CsvVehicleParser;
use PHPUnit\Framework\TestCase;

final class CsvVehicleParserTest extends TestCase
{
    public function test_skips_header_and_numbers_first_data_row_as_two(): void
    {
        $csv = "col_a,col_b,col_c\n1,2,3\n4,5,6\n";

        $rows = (new CsvVehicleParser())->parse($csv);

        self::assertSame([
            [2, ['1', '2', '3']],
            [3, ['4', '5', '6']],
        ], $rows);
    }

    public function test_returns_positional_arrays_not_header_keyed_maps(): void
    {
        $csv = "vin,model\nABC,Accord\n";

        $rows = (new CsvVehicleParser())->parse($csv);

        self::assertSame([[2, ['ABC', 'Accord']]], $rows);
        self::assertArrayHasKey(0, $rows[0][1]);
        self::assertArrayHasKey(1, $rows[0][1]);
    }

    public function test_empty_body_yields_no_rows(): void
    {
        $rows = (new CsvVehicleParser())->parse("only_header\n");

        self::assertSame([], $rows);
    }
}
