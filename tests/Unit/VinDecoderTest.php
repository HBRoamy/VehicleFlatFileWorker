<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\VinDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VinDecoderTest extends TestCase
{
    private VinDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new VinDecoder();
    }

    public function test_computes_canonical_iso_3779_check_digit(): void
    {
        // Canonical worked example: check digit at position 9 is '3'.
        self::assertSame('3', $this->decoder->computeCheckDigit('1HGCM82633A004352'));
    }

    public function test_check_digit_is_always_a_single_valid_symbol(): void
    {
        self::assertMatchesRegularExpression('/^[0-9X]$/', $this->decoder->computeCheckDigit('5YJ3E1EAXHF000316'));
    }

    #[DataProvider('validVins')]
    public function test_accepts_vins_with_a_correct_check_digit(string $vin): void
    {
        self::assertTrue($this->decoder->hasValidCheckDigit($vin), $vin.' should be valid');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validVins(): iterable
    {
        yield 'accord'  => ['1HGCM82633A004352'];
        yield 'civic'   => ['JHMFA16596S000123'];
        yield 'tesla-x' => ['5YJ3E1EAXHF000316'];
        yield 'swift'   => ['MA3ERLF1800000001'];
        yield 'clio'    => ['VF1RFB00000000001'];
    }

    public function test_rejects_a_tampered_check_digit(): void
    {
        // Same as the valid Accord but position 9 changed from '3' to '0'.
        self::assertFalse($this->decoder->hasValidCheckDigit('1HGCM82603A004352'));
    }

    public function test_rejects_wrong_length(): void
    {
        self::assertFalse($this->decoder->hasValidCheckDigit('1HGCM82633'));
    }

    public function test_extracts_wmi_uppercased(): void
    {
        self::assertSame('1HG', $this->decoder->wmi('1hgcm82633a004352'));
    }

    #[DataProvider('regionCountry')]
    public function test_decodes_region_and_country(string $vin, string $region, string $country): void
    {
        self::assertSame($region, $this->decoder->region($vin));
        self::assertSame($country, $this->decoder->country($vin));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function regionCountry(): iterable
    {
        yield 'usa'     => ['1HGCM82633A004352', 'North America', 'United States'];
        yield 'japan'   => ['JHMFA16596S000123', 'Asia', 'Japan'];
        yield 'germany' => ['WBA8E9G52HNU12345', 'Europe', 'Germany'];
        yield 'india'   => ['MA3ERLF1800000001', 'Asia', 'India'];
        yield 'france'  => ['VF1RFB00000000001', 'Europe', 'France'];
    }
}
