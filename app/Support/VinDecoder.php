<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decodes and validates a 17-character VIN per ISO 3779 / FMVSS 115.
 *
 * Two responsibilities:
 *  1. Verify the check digit (position 9), which is a weighted modulo-11
 *     checksum over the transliterated VIN. This catches transposition and
 *     mistyping errors that a simple charset/length check would miss.
 *  2. Decode the World Manufacturer Identifier (first 3 characters) into a
 *     geographic region and country, which are persisted alongside the record.
 *
 * All methods are pure and framework-free so they run identically inside a
 * parallel worker and in unit tests.
 */
final class VinDecoder
{
    /**
     * Transliteration table mapping VIN characters to their numeric value.
     * The letters I, O and Q are intentionally absent (forbidden in VINs).
     *
     * @var array<string, int>
     */
    private const TRANSLITERATION = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4,
        '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9,
    ];

    /**
     * Positional weights (index 0..16). Position 9 (index 8) carries weight 0
     * because it is the check digit itself.
     *
     * @var list<int>
     */
    private const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * Compute the correct check-digit character ('0'..'9' or 'X') for a VIN.
     * Assumes the VIN is already 17 valid characters.
     */
    public function computeCheckDigit(string $vin): string
    {
        $vin = strtoupper($vin);
        $sum = 0;

        for ($i = 0; $i < 17; $i++) {
            $char = $vin[$i];
            $value = self::TRANSLITERATION[$char] ?? 0;
            $sum += $value * self::WEIGHTS[$i];
        }

        $remainder = $sum % 11;

        return $remainder === 10 ? 'X' : (string) $remainder;
    }

    /**
     * Whether the VIN's actual check digit matches the computed one.
     */
    public function hasValidCheckDigit(string $vin): bool
    {
        if (strlen($vin) !== 17) {
            return false;
        }

        return strtoupper($vin[8]) === $this->computeCheckDigit($vin);
    }

    /**
     * The World Manufacturer Identifier: the first three characters.
     */
    public function wmi(string $vin): string
    {
        return strtoupper(substr($vin, 0, 3));
    }

    /**
     * Broad manufacturing region derived from the first character.
     */
    public function region(string $vin): string
    {
        $first = strtoupper($vin[0] ?? '');

        return match (true) {
            $first >= 'A' && $first <= 'H' => 'Africa',
            $first >= 'J' && $first <= 'R' => 'Asia',
            $first >= 'S' && $first <= 'Z' => 'Europe',
            $first >= '1' && $first <= '5' => 'North America',
            $first === '6' || $first === '7' => 'Oceania',
            $first === '8' || $first === '9' || $first === '0' => 'South America',
            default => 'Unknown',
        };
    }

    /**
     * Best-effort country of manufacture, keyed on the first one or two
     * characters. Returns 'Unknown' when the code is outside the curated set.
     */
    public function country(string $vin): string
    {
        $first = strtoupper($vin[0] ?? '');
        $two = strtoupper(substr($vin, 0, 2));

        // Single-character determinations first.
        $byFirst = match ($first) {
            '1', '4', '5' => 'United States',
            '2'           => 'Canada',
            '3'           => 'Mexico',
            'J'           => 'Japan',
            'K'           => 'South Korea',
            'L'           => 'China',
            'W'           => 'Germany',
            'Z'           => 'Italy',
            default       => null,
        };

        if ($byFirst !== null) {
            return $byFirst;
        }

        // Two-character ranges for the remaining common cases. Both endpoints
        // are letters, so the lexicographic comparison is well-defined.
        return match (true) {
            $two >= 'MA' && $two <= 'ME' => 'India',
            $two >= 'SA' && $two <= 'SM' => 'United Kingdom',
            $two >= 'VF' && $two <= 'VR' => 'France',
            $two >= 'VS' && $two <= 'VW' => 'Spain',
            $two >= 'YS' && $two <= 'YW' => 'Sweden',
            default                      => 'Unknown',
        };
    }
}
