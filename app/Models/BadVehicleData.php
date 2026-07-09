<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A rejected row stored in the `bad_vehicle_data` table for later inspection.
 *
 * @property int         $id
 * @property string|null $vin
 * @property int         $row_number
 * @property string      $file_name
 * @property string      $raw_row_data
 * @property string      $error_reason
 * @property \Illuminate\Support\Carbon $created_at
 */
final class BadVehicleData extends Model
{
    protected $table = 'bad_vehicle_data';

    public const UPDATED_AT = null;

    protected $fillable = [
        'vin',
        'row_number',
        'file_name',
        'raw_row_data',
        'error_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
        ];
    }
}
