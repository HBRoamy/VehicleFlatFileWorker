<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Transmission type. Backed by the lowercase token expected in the CSV.
 */
enum TransmissionType: string
{
    case Automatic = 'automatic';
    case Manual    = 'manual';
    case Cvt       = 'cvt';

    public static function fromLabel(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
