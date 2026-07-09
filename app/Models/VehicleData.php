<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A validated vehicle record stored in the `vehicle_data` table.
 *
 * @property int         $id
 * @property string      $vin
 * @property string      $wmi
 * @property string      $vin_region
 * @property string      $vin_country
 * @property string      $model
 * @property int         $model_year
 * @property string|null $trim
 * @property string      $body_style
 * @property float       $current_price_usd
 * @property float       $msrp_usd
 * @property bool        $carfax_certified
 * @property string      $pincode
 * @property bool        $is_new
 * @property int         $mileage
 * @property int         $previous_owners
 * @property string      $exterior_color
 * @property string      $interior_color
 * @property string      $engine_type
 * @property string      $transmission
 * @property float|null  $fuel_efficiency_mpg
 * @property string      $manufacture_date
 * @property string|null $registration_date
 * @property array       $features
 * @property \Illuminate\Support\Carbon $last_updated
 */
final class VehicleData extends Model
{
    protected $table = 'vehicle_data';

    public $timestamps = false;

    protected $fillable = [
        'vin', 'wmi', 'vin_region', 'vin_country', 'model', 'model_year', 'trim',
        'body_style', 'current_price_usd', 'msrp_usd', 'carfax_certified', 'pincode',
        'is_new', 'mileage', 'previous_owners', 'exterior_color', 'interior_color',
        'engine_type', 'transmission', 'fuel_efficiency_mpg', 'manufacture_date',
        'registration_date', 'features', 'last_updated',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'model_year'          => 'integer',
            'current_price_usd'   => 'decimal:2',
            'msrp_usd'            => 'decimal:2',
            'carfax_certified'    => 'boolean',
            'is_new'              => 'boolean',
            'mileage'             => 'integer',
            'previous_owners'     => 'integer',
            'fuel_efficiency_mpg' => 'decimal:1',
            'features'            => 'array',
            'manufacture_date'    => 'date:Y-m-d',
            'registration_date'   => 'date:Y-m-d',
            'last_updated'        => 'datetime',
        ];
    }
}
