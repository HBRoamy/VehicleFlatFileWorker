<?php

declare(strict_types=1);

namespace App\DataTransfer;

use App\Enums\BodyStyle;
use App\Enums\EngineType;
use App\Enums\TransmissionType;

/**
 * Immutable, fully-typed representation of a single valid vehicle row.
 *
 * Includes both the values read from the CSV and the fields derived from the
 * VIN during validation (WMI, region, country). It is intentionally free of
 * any framework/database coupling so it can be serialized and returned from a
 * parallel worker process to the parent, which performs the batched DB write.
 */
final readonly class VehicleRecord
{
    /**
     * @param array<string, mixed> $features Decoded, schema-checked feature map.
     */
    public function __construct(
        public string $vin,
        public string $wmi,
        public string $vinRegion,
        public string $vinCountry,
        public string $model,
        public int $modelYear,
        public ?string $trim,
        public BodyStyle $bodyStyle,
        public float $currentPriceUsd,
        public float $msrpUsd,
        public bool $carfaxCertified,
        public string $pincode,
        public bool $isNew,
        public int $mileage,
        public int $previousOwners,
        public string $exteriorColor,
        public string $interiorColor,
        public EngineType $engineType,
        public TransmissionType $transmission,
        public ?float $fuelEfficiencyMpg,
        public string $manufactureDate,
        public ?string $registrationDate,
        public array $features,
    ) {
    }

    /**
     * Convert to the column => value shape used for the batched DB insert.
     * `last_updated` is stamped by the processor at commit time, not here.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'vin'                 => $this->vin,
            'wmi'                 => $this->wmi,
            'vin_region'          => $this->vinRegion,
            'vin_country'         => $this->vinCountry,
            'model'               => $this->model,
            'model_year'          => $this->modelYear,
            'trim'                => $this->trim,
            'body_style'          => $this->bodyStyle->value,
            'current_price_usd'   => $this->currentPriceUsd,
            'msrp_usd'            => $this->msrpUsd,
            'carfax_certified'    => $this->carfaxCertified,
            'pincode'             => $this->pincode,
            'is_new'              => $this->isNew,
            'mileage'             => $this->mileage,
            'previous_owners'     => $this->previousOwners,
            'exterior_color'      => $this->exteriorColor,
            'interior_color'      => $this->interiorColor,
            'engine_type'         => $this->engineType->value,
            'transmission'        => $this->transmission->value,
            'fuel_efficiency_mpg' => $this->fuelEfficiencyMpg,
            'manufacture_date'    => $this->manufactureDate,
            'registration_date'   => $this->registrationDate,
            'features'            => json_encode($this->features, JSON_THROW_ON_ERROR),
        ];
    }
}
