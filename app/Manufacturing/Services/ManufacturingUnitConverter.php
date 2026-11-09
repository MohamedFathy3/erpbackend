<?php

namespace App\Manufacturing\Services;

use RuntimeException;

class ManufacturingUnitConverter
{
    private const UNITS = [
        'mm' => ['length', 1],
        'millimeter' => ['length', 1],
        'cm' => ['length', 10],
        'm' => ['length', 1000],
        'meter' => ['length', 1000],
        'cm2' => ['area', 1],
        'm2' => ['area', 10000],
        'mg' => ['mass', 0.001],
        'g' => ['mass', 1],
        'kg' => ['mass', 1000],
        'ml' => ['volume', 1],
        'cc' => ['volume', 1],
        'l' => ['volume', 1000],
        'liter' => ['volume', 1000],
        'litre' => ['volume', 1000],
        'm3' => ['volume', 1000000],
        'piece' => ['count', 1],
        'pieces' => ['count', 1],
        'pc' => ['count', 1],
        'pcs' => ['count', 1],
        'ea' => ['count', 1],
        'unit' => ['count', 1],
        'units' => ['count', 1],
    ];

    public static function convert(
        float $quantity,
        ?string $from,
        ?string $to,
        ?string $baseUnit = null,
        ?string $stockUnit = null,
        ?float $conversionFactor = null,
    ): float {
        $fromKey = self::key($from);
        $toKey = self::key($to);
        if ($fromKey === '' || $toKey === '') {
            throw new RuntimeException('Both source and stock units must be configured for a BOM component.');
        }
        if ($fromKey === $toKey) {
            return $quantity;
        }

        $fromDefinition = self::UNITS[$fromKey] ?? null;
        $toDefinition = self::UNITS[$toKey] ?? null;
        if ($fromDefinition && $toDefinition && $fromDefinition[0] === $toDefinition[0]) {
            return $quantity * $fromDefinition[1] / $toDefinition[1];
        }

        if (
            $conversionFactor !== null
            && $conversionFactor > 0
            && $baseUnit !== null
            && $stockUnit !== null
            && $fromKey === self::key($baseUnit)
            && $toKey === self::key($stockUnit)
        ) {
            return $quantity / $conversionFactor;
        }
        if (
            $conversionFactor !== null
            && $conversionFactor > 0
            && $baseUnit !== null
            && $stockUnit !== null
            && $fromKey === self::key($stockUnit)
            && $toKey === self::key($baseUnit)
        ) {
            return $quantity * $conversionFactor;
        }

        throw new RuntimeException("Cannot convert BOM unit {$from} to stock unit {$to}; the units are incompatible.");
    }

    private static function key(?string $unit): string
    {
        return strtolower(trim((string) $unit));
    }
}
