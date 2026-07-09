<?php

declare(strict_types=1);

namespace App\Validation;

use App\DataTransfer\VehicleRecord;
use App\Enums\BodyStyle;
use App\Enums\EngineType;
use App\Enums\TransmissionType;
use App\Support\VinDecoder;
use DateTimeImmutable;

/**
 * Parses and strictly validates a single raw CSV row.
 *
 * Expected column order (0-based):
 *   0  vin                  9  is_new
 *   1  model               10  mileage
 *   2  model_year          11  previous_owners
 *   3  trim                12  exterior_color
 *   4  body_style          13  interior_color
 *   5  current_price_usd   14  engine_type
 *   6  msrp_usd            15  transmission
 *   7  carfax_certified    16  fuel_efficiency_mpg
 *   8  pincode             17  manufacture_date
 *                          18  registration_date
 *                          19  features (JSON)
 *
 * Validation is fail-collecting: every problem in a row is reported so the
 * bad-data record carries the complete picture. Beyond per-field checks, the
 * validator performs genuinely non-trivial work per row: the ISO 3779 VIN
 * check-digit computation, WMI geographic decoding, calendar date parsing, and
 * a set of cross-field business rules.
 */
final class VehicleRecordValidator
{
    public const EXPECTED_COLUMN_COUNT = 20;

    /** @var list<string> */
    public const HEADER = [
        'vin', 'model', 'model_year', 'trim', 'body_style',
        'current_price_usd', 'msrp_usd', 'carfax_certified', 'pincode', 'is_new',
        'mileage', 'previous_owners', 'exterior_color', 'interior_color', 'engine_type',
        'transmission', 'fuel_efficiency_mpg', 'manufacture_date', 'registration_date', 'features',
    ];

    private const MIN_MODEL_YEAR = 1900;
    private const MAX_NEW_VEHICLE_MILEAGE = 100;
    private const ALLOWED_HEADLAMPS = ['halogen', 'led', 'laser', 'xenon'];

    public function __construct(
        private readonly VinDecoder $vinDecoder = new VinDecoder(),
    ) {
    }

    /**
     * @param array<int, string> $row
     *
     * @return array{0: VehicleRecord|null, 1: list<string>}
     */
    public function validate(array $row): array
    {
        if (count($row) !== self::EXPECTED_COLUMN_COUNT) {
            return [
                null,
                [sprintf('Expected %d columns but found %d.', self::EXPECTED_COLUMN_COUNT, count($row))],
            ];
        }

        $row = array_map(static fn (string $v): string => trim($v), $row);
        [
            $vinRaw, $model, $yearRaw, $trimRaw, $bodyRaw,
            $priceRaw, $msrpRaw, $carfaxRaw, $pincode, $isNewRaw,
            $mileageRaw, $prevOwnersRaw, $extColor, $intColor, $engineRaw,
            $transRaw, $mpgRaw, $mfgDateRaw, $regDateRaw, $featuresRaw,
        ] = $row;

        $errors = [];

        $vin = strtoupper($vinRaw);
        $vinCharsetOk = (bool) preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin);
        if (!$vinCharsetOk) {
            $errors[] = 'VIN must be exactly 17 characters, alphanumeric, excluding I, O and Q.';
        } elseif (!$this->vinDecoder->hasValidCheckDigit($vin)) {
            $errors[] = sprintf('VIN check digit is invalid (position 9 should be "%s").', $this->vinDecoder->computeCheckDigit($vin));
        }

        if ($model === '') {
            $errors[] = 'Model is required.';
        }

        $modelYear = null;
        if (!$this->isIntegerString($yearRaw)) {
            $errors[] = 'Model year must be an integer.';
        } else {
            $modelYear = (int) $yearRaw;
            $maxYear = (int) date('Y') + 1;
            if ($modelYear < self::MIN_MODEL_YEAR || $modelYear > $maxYear) {
                $errors[] = sprintf('Model year %d is out of range %d-%d.', $modelYear, self::MIN_MODEL_YEAR, $maxYear);
            }
        }

        $trim = $trimRaw === '' ? null : $trimRaw;

        $bodyStyle = BodyStyle::fromLabel($bodyRaw);
        if ($bodyStyle === null) {
            $errors[] = sprintf('Body style "%s" is not recognised.', $bodyRaw);
        }

        $price = $this->parseNonNegativeDecimal($priceRaw, 'Current price', $errors);
        $msrp = $this->parseNonNegativeDecimal($msrpRaw, 'MSRP', $errors);

        $carfax = $this->parseBool($carfaxRaw);
        if ($carfax === null) {
            $errors[] = 'Carfax certified must be a boolean.';
        }

        if ($pincode === '') {
            $errors[] = 'Dealership pincode is required.';
        }

        $isNew = $this->parseBool($isNewRaw);
        if ($isNew === null) {
            $errors[] = 'New flag is required and must be a boolean.';
        }

        $mileage = $this->parseNonNegativeInt($mileageRaw, 'Mileage', $errors);
        $previousOwners = $this->parseNonNegativeInt($prevOwnersRaw, 'Previous owners', $errors);

        if ($extColor === '') {
            $errors[] = 'Exterior color is required.';
        }
        if ($intColor === '') {
            $errors[] = 'Interior color is required.';
        }

        $engine = EngineType::fromLabel($engineRaw);
        if ($engine === null) {
            $errors[] = sprintf('Engine type "%s" is not recognised.', $engineRaw);
        }

        $transmission = TransmissionType::fromLabel($transRaw);
        if ($transmission === null) {
            $errors[] = sprintf('Transmission "%s" is not recognised.', $transRaw);
        }

        $mpg = null;
        if ($engine !== null && $engine->requiresFuelEfficiency()) {
            if ($mpgRaw === '' || !$this->isDecimalString($mpgRaw) || (float) $mpgRaw <= 0) {
                $errors[] = 'Fuel efficiency (MPG) must be a positive number for non-electric vehicles.';
            } else {
                $mpg = (float) $mpgRaw;
            }
        } elseif ($mpgRaw !== '') {
            if (!$this->isDecimalString($mpgRaw)) {
                $errors[] = 'Fuel efficiency must be numeric or blank.';
            } else {
                $mpg = (float) $mpgRaw;
            }
        }

        $manufactureDate = $this->parseDate($mfgDateRaw, 'Manufacture date', true, $errors);
        $registrationDate = $this->parseDate($regDateRaw, 'Registration date', false, $errors);

        $features = $this->parseFeatures($featuresRaw, $errors);

        $this->applyCrossFieldRules($modelYear, $manufactureDate, $registrationDate, $isNew, $mileage, $previousOwners, $errors);

        if ($errors !== []) {
            return [null, $errors];
        }

        /** @var array<string, mixed> $features */
        $record = new VehicleRecord(
            vin: $vin,
            wmi: $this->vinDecoder->wmi($vin),
            vinRegion: $this->vinDecoder->region($vin),
            vinCountry: $this->vinDecoder->country($vin),
            model: $model,
            modelYear: (int) $modelYear,
            trim: $trim,
            bodyStyle: $bodyStyle,
            currentPriceUsd: (float) $price,
            msrpUsd: (float) $msrp,
            carfaxCertified: (bool) $carfax,
            pincode: $pincode,
            isNew: (bool) $isNew,
            mileage: (int) $mileage,
            previousOwners: (int) $previousOwners,
            exteriorColor: $extColor,
            interiorColor: $intColor,
            engineType: $engine,
            transmission: $transmission,
            fuelEfficiencyMpg: $mpg,
            manufactureDate: (string) $manufactureDate?->format('Y-m-d'),
            registrationDate: $registrationDate?->format('Y-m-d'),
            features: $features,
        );

        return [$record, []];
    }

    /**
     * @param list<string> $errors
     */
    private function applyCrossFieldRules(
        ?int $modelYear,
        ?DateTimeImmutable $manufactureDate,
        ?DateTimeImmutable $registrationDate,
        ?bool $isNew,
        ?int $mileage,
        ?int $previousOwners,
        array &$errors,
    ): void {
        if ($modelYear !== null && $manufactureDate !== null) {
            $mfgYear = (int) $manufactureDate->format('Y');
            if ($modelYear < $mfgYear || $modelYear > $mfgYear + 1) {
                $errors[] = sprintf('Model year %d is inconsistent with manufacture year %d (expected %d or %d).', $modelYear, $mfgYear, $mfgYear, $mfgYear + 1);
            }
        }

        if ($manufactureDate !== null && $registrationDate !== null && $registrationDate < $manufactureDate) {
            $errors[] = 'Registration date cannot be earlier than the manufacture date.';
        }

        if ($isNew === true) {
            if ($previousOwners !== null && $previousOwners !== 0) {
                $errors[] = 'A new vehicle cannot have previous owners.';
            }
            if ($mileage !== null && $mileage > self::MAX_NEW_VEHICLE_MILEAGE) {
                $errors[] = sprintf('A new vehicle cannot have more than %d miles (got %d).', self::MAX_NEW_VEHICLE_MILEAGE, $mileage);
            }
        }
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>|null
     */
    private function parseFeatures(string $raw, array &$errors): ?array
    {
        if ($raw === '') {
            $errors[] = 'Features JSON is required.';

            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $errors[] = 'Features must be a valid JSON object.';

            return null;
        }

        if (!array_key_exists('airbags', $decoded)) {
            $errors[] = 'Features must include an "airbags" count.';
        } elseif (!is_int($decoded['airbags']) || $decoded['airbags'] < 0) {
            $errors[] = 'Features "airbags" must be a non-negative integer.';
        }

        if (array_key_exists('sunroof', $decoded) && !is_bool($decoded['sunroof'])) {
            $errors[] = 'Features "sunroof" must be a boolean.';
        }

        if (array_key_exists('headlamps', $decoded)) {
            $lamp = is_string($decoded['headlamps']) ? strtolower($decoded['headlamps']) : '';
            if (!in_array($lamp, self::ALLOWED_HEADLAMPS, true)) {
                $errors[] = sprintf('Features "headlamps" must be one of: %s.', implode(', ', self::ALLOWED_HEADLAMPS));
            }
        }

        if (array_key_exists('driverAssist', $decoded)) {
            $da = $decoded['driverAssist'];
            $valid = is_array($da) && array_is_list($da)
                && array_reduce($da, static fn (bool $c, $v): bool => $c && is_string($v), true);
            if (!$valid) {
                $errors[] = 'Features "driverAssist" must be a list of strings.';
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param list<string> $errors
     */
    private function parseDate(string $raw, string $label, bool $required, array &$errors): ?DateTimeImmutable
    {
        if ($raw === '') {
            if ($required) {
                $errors[] = sprintf('%s is required.', $label);
            }

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $parseErrors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))) {
            $errors[] = sprintf('%s must be a valid date in YYYY-MM-DD format.', $label);

            return null;
        }

        if ($date > new DateTimeImmutable('today')) {
            $errors[] = sprintf('%s cannot be in the future.', $label);

            return null;
        }

        return $date;
    }

    /**
     * @param list<string> $errors
     */
    private function parseNonNegativeDecimal(string $raw, string $label, array &$errors): ?float
    {
        if (!$this->isDecimalString($raw)) {
            $errors[] = sprintf('%s must be a non-negative number.', $label);

            return null;
        }

        $value = (float) $raw;
        if ($value < 0) {
            $errors[] = sprintf('%s must not be negative.', $label);

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     */
    private function parseNonNegativeInt(string $raw, string $label, array &$errors): ?int
    {
        if (!$this->isIntegerString($raw) || (int) $raw < 0) {
            $errors[] = sprintf('%s must be a non-negative integer.', $label);

            return null;
        }

        return (int) $raw;
    }

    private function isIntegerString(string $value): bool
    {
        return $value !== '' && preg_match('/^-?\d+$/', $value) === 1;
    }

    private function isDecimalString(string $value): bool
    {
        return $value !== '' && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }

    private function parseBool(string $value): ?bool
    {
        return match (strtolower($value)) {
            'true', '1', 'yes', 'y' => true,
            'false', '0', 'no', 'n' => false,
            default                 => null,
        };
    }
}
