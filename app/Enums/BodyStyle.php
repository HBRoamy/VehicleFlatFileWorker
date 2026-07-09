<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Vehicle body style. Backed by the lowercase token expected in the CSV.
 */
enum BodyStyle: string
{
    case Sedan       = 'sedan';
    case Suv         = 'suv';
    case Truck       = 'truck';
    case Coupe       = 'coupe';
    case Hatchback   = 'hatchback';
    case Van         = 'van';
    case Wagon       = 'wagon';
    case Convertible = 'convertible';

    public static function fromLabel(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
