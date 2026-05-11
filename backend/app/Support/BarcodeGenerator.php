<?php

namespace App\Support;

use App\Models\Product;

/**
 * EAN-13 con prefijo 2 (uso interno / tienda). Dígito de control estándar GS1.
 */
final class BarcodeGenerator
{
    public static function ean13WithCheckDigit(string $first12): string
    {
        if (strlen($first12) !== 12 || ! ctype_digit($first12)) {
            throw new \InvalidArgumentException('Se requieren 12 dígitos numéricos.');
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * (($i % 2 === 0) ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;

        return $first12.$check;
    }

    /**
     * Código único en products.barcode (reintenta si colisión).
     */
    public static function randomUniqueEan13(): string
    {
        do {
            $body = '2'.str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);
            $code = self::ean13WithCheckDigit($body);
        } while (Product::query()->where('barcode', $code)->exists());

        return $code;
    }
}
