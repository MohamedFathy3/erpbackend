<?php

namespace Tests\Feature;

use App\Manufacturing\Services\ManufacturingUnitConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ManufacturingUnitConverterTest extends TestCase
{
    #[DataProvider('compatibleConversions')]
    public function test_it_converts_compatible_units(
        float $quantity,
        string $from,
        string $to,
        float $expected,
    ): void {
        $this->assertEqualsWithDelta(
            $expected,
            ManufacturingUnitConverter::convert($quantity, $from, $to),
            0.000001,
        );
    }

    public static function compatibleConversions(): array
    {
        return [
            'liters to milliliters' => [1.5, 'L', 'ML', 1500],
            'kilograms to grams' => [2, 'kg', 'g', 2000],
            'meters to centimeters' => [1.25, 'm', 'cm', 125],
            'same custom unit' => [3, 'roll', 'ROLL', 3],
        ];
    }

    public function test_it_uses_the_configured_pack_ratio_for_custom_units(): void
    {
        $this->assertEqualsWithDelta(
            2,
            ManufacturingUnitConverter::convert(24, 'piece', 'box', 'piece', 'box', 12),
            0.000001,
        );
    }

    public function test_it_rejects_incompatible_units(): void
    {
        $this->expectException(RuntimeException::class);
        ManufacturingUnitConverter::convert(1, 'kg', 'L');
    }
}
