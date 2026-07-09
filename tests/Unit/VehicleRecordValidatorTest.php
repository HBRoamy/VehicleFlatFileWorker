<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BodyStyle;
use App\Enums\EngineType;
use App\Enums\TransmissionType;
use App\Validation\VehicleRecordValidator;
use PHPUnit\Framework\TestCase;

final class VehicleRecordValidatorTest extends TestCase
{
    private VehicleRecordValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new VehicleRecordValidator();
    }

    /**
     * A fully valid gasoline row (the sample "Accord"). Returns 20 columns.
     *
     * @return array<int, string>
     */
    private function validGasolineRow(): array
    {
        return [
            '1HGCM82633A004352', 'Accord', '2022', 'EX-L', 'sedan',
            '28999', '31000', 'true', '560001', 'false',
            '15000', '1', 'Blue', 'Black', 'gasoline',
            'automatic', '32.5', '2021-11-05', '2022-01-10',
            '{"airbags":6,"sunroof":true,"headlamps":"led","driverAssist":["lane-keep","acc"]}',
        ];
    }

    /**
     * A fully valid electric row (the sample "Model 3"): no MPG required.
     *
     * @return array<int, string>
     */
    private function validElectricRow(): array
    {
        return [
            '5YJ3E1EAXHF000316', 'Model 3', '2024', 'Long Range', 'sedan',
            '44990', '47000', 'false', '560037', 'true',
            '12', '0', 'Red', 'White', 'electric',
            'automatic', '', '2024-02-20', '',
            '{"airbags":8,"sunroof":true,"headlamps":"led","driverAssist":["autopilot"]}',
        ];
    }

    public function test_accepts_a_valid_gasoline_row_and_derives_vin_fields(): void
    {
        [$record, $errors] = $this->validator->validate($this->validGasolineRow());

        self::assertSame([], $errors);
        self::assertNotNull($record);
        self::assertSame('1HGCM82633A004352', $record->vin);
        self::assertSame('1HG', $record->wmi);
        self::assertSame('North America', $record->vinRegion);
        self::assertSame('United States', $record->vinCountry);
        self::assertSame(BodyStyle::Sedan, $record->bodyStyle);
        self::assertSame(EngineType::Gasoline, $record->engineType);
        self::assertSame(TransmissionType::Automatic, $record->transmission);
        self::assertSame(6, $record->features['airbags']);
    }

    public function test_accepts_a_valid_electric_row_without_mpg(): void
    {
        [$record, $errors] = $this->validator->validate($this->validElectricRow());

        self::assertSame([], $errors);
        self::assertNotNull($record);
        self::assertNull($record->fuelEfficiencyMpg);
        self::assertNull($record->registrationDate);
    }

    public function test_rejects_wrong_column_count(): void
    {
        $row = array_slice($this->validGasolineRow(), 0, 19);

        [$record, $errors] = $this->validator->validate($row);

        self::assertNull($record);
        self::assertStringContainsString('Expected 20 columns', $errors[0]);
    }

    public function test_collects_multiple_errors_in_one_pass(): void
    {
        $row = $this->validGasolineRow();
        $row[1] = '';        // model missing
        $row[7] = 'maybe';   // carfax not boolean
        $row[8] = '';        // pincode missing

        [$record, $errors] = $this->validator->validate($row);

        self::assertNull($record);
        self::assertGreaterThanOrEqual(3, count($errors));
    }

    public function test_rejects_bad_vin_charset(): void
    {
        $row = $this->validGasolineRow();
        $row[0] = 'IOQ0000000000000O';

        $errors = $this->errorsFor($row);

        self::assertTrue($this->anyContains($errors, 'VIN must be exactly 17'));
    }

    public function test_rejects_invalid_check_digit(): void
    {
        $row = $this->validGasolineRow();
        $row[0] = '1HGCM82603A004352'; // tampered check digit

        $errors = $this->errorsFor($row);

        self::assertTrue($this->anyContains($errors, 'check digit'));
    }

    public function test_rejects_non_integer_model_year(): void
    {
        $row = $this->validGasolineRow();
        $row[2] = '20x2';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'Model year must be an integer'));
    }

    public function test_rejects_out_of_range_model_year(): void
    {
        $row = $this->validGasolineRow();
        $row[2] = '1800';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'out of range'));
    }

    public function test_rejects_negative_price(): void
    {
        $row = $this->validGasolineRow();
        $row[5] = '-100';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'must not be negative'));
    }

    public function test_rejects_invalid_body_style(): void
    {
        $row = $this->validGasolineRow();
        $row[4] = 'spaceship';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'Body style'));
    }

    public function test_requires_mpg_for_non_electric(): void
    {
        $row = $this->validGasolineRow();
        $row[16] = ''; // gasoline vehicle with no MPG

        self::assertTrue($this->anyContains($this->errorsFor($row), 'Fuel efficiency'));
    }

    public function test_rejects_missing_airbags_in_features(): void
    {
        $row = $this->validGasolineRow();
        $row[19] = '{"sunroof":true}';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'airbags'));
    }

    public function test_rejects_malformed_features_json(): void
    {
        $row = $this->validGasolineRow();
        $row[19] = 'not-json';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'valid JSON object'));
    }

    public function test_rejects_invalid_headlamps_enum(): void
    {
        $row = $this->validGasolineRow();
        $row[19] = '{"airbags":6,"headlamps":"plasma"}';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'headlamps'));
    }

    public function test_rejects_future_manufacture_date(): void
    {
        $row = $this->validGasolineRow();
        $row[17] = '2999-01-01';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'cannot be in the future'));
    }

    public function test_rejects_malformed_date_format(): void
    {
        $row = $this->validGasolineRow();
        $row[17] = '10-09-2020';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'valid date'));
    }

    public function test_rejects_registration_before_manufacture(): void
    {
        $row = $this->validGasolineRow();
        $row[17] = '2022-05-01'; // manufacture
        $row[18] = '2022-04-01'; // registration earlier
        $row[2]  = '2022';        // keep model year consistent with mfg year

        self::assertTrue($this->anyContains($this->errorsFor($row), 'Registration date cannot be earlier'));
    }

    public function test_rejects_model_year_inconsistent_with_manufacture_year(): void
    {
        $row = $this->validGasolineRow();
        $row[2]  = '2022';
        $row[17] = '2019-01-01'; // manufacture year two off from model year

        self::assertTrue($this->anyContains($this->errorsFor($row), 'inconsistent with manufacture year'));
    }

    public function test_rejects_new_vehicle_with_previous_owners(): void
    {
        $row = $this->validGasolineRow();
        $row[9]  = 'true'; // is_new
        $row[11] = '2';    // previous owners
        $row[10] = '5';    // low mileage
        $row[2]  = '2022';
        $row[17] = '2022-01-01';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'cannot have previous owners'));
    }

    public function test_rejects_new_vehicle_with_high_mileage(): void
    {
        $row = $this->validGasolineRow();
        $row[9]  = 'true'; // is_new
        $row[11] = '0';    // no previous owners
        $row[10] = '5000'; // too many miles for a new car
        $row[2]  = '2022';
        $row[17] = '2022-01-01';

        self::assertTrue($this->anyContains($this->errorsFor($row), 'more than 100 miles'));
    }

    /**
     * @param array<int, string> $row
     *
     * @return list<string>
     */
    private function errorsFor(array $row): array
    {
        [$record, $errors] = $this->validator->validate($row);

        self::assertNull($record, 'expected the row to be rejected');

        return $errors;
    }

    /**
     * @param list<string> $errors
     */
    private function anyContains(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
