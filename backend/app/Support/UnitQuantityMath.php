<?php

namespace App\Support;

/**
 * Operaciones con cantidades para conversiones (evita pérdida grosera con BCMath si está disponible).
 */
final class UnitQuantityMath
{
    public static function multiply(string $a, string $b, int $scale = 8): string
    {
        if (function_exists('bcmul')) {
            return bcmul($a, $b, $scale);
        }

        return (string) round((float) $a * (float) $b, $scale);
    }

    public static function divide(string $a, string $b, int $scale = 8): string
    {
        if ((float) $b === 0.0) {
            throw new \InvalidArgumentException('Divisor cero.');
        }

        if (function_exists('bcdiv')) {
            return bcdiv($a, $b, $scale);
        }

        return (string) round((float) $a / (float) $b, $scale);
    }
}
