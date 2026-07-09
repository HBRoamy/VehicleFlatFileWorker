<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Powertrain type. Backed by the lowercase token expected in the CSV.
 */
enum EngineType: string
{
    case Gasoline = 'gasoline';
    case Diesel   = 'diesel';
    case Hybrid   = 'hybrid';
    case Electric = 'electric';

    /**
     * Whether this powertrain is expected to report a fuel-efficiency figure.
     * Battery-electric vehicles do not have an MPG rating.
     */
    public function requiresFuelEfficiency(): bool
    {
        return $this !== self::Electric;
    }

    public static function fromLabel(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
