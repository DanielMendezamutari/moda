<?php

namespace App\Support;

use App\Models\Product;

final class SkuGenerator
{
    /**
     * SKU único en products.sku (reintenta si colisión).
     */
    public static function randomUnique(): string
    {
        do {
            $sku = 'PRD-'.strtoupper(bin2hex(random_bytes(4)));
        } while (Product::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
