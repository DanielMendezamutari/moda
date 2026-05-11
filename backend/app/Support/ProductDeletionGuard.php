<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProductDeletionGuard
{
    /**
     * Impide eliminar productos ligados a ventas, compras o transportes documentados.
     *
     * @throws ValidationException
     */
    public static function assertDeletable(Product $product): void
    {
        $id = $product->id;
        $blocks = [];

        if (Schema::hasTable('sale_details') && DB::table('sale_details')->where('product_id', $id)->exists()) {
            $blocks[] = 'ventas (detalle de venta)';
        }
        if (Schema::hasTable('purchase_items') && DB::table('purchase_items')->where('product_id', $id)->exists()) {
            $blocks[] = 'compras (detalle de compra)';
        }
        if (Schema::hasTable('transport_details') && DB::table('transport_details')->where('product_id', $id)->exists()) {
            $blocks[] = 'transportes (detalle de movimiento)';
        }
        if (Schema::hasTable('conversions') && DB::table('conversions')->where('product_id', $id)->exists()) {
            $blocks[] = 'conversiones de unidades (inventario)';
        }

        if ($blocks === []) {
            return;
        }

        throw ValidationException::withMessages([
            'product' => [
                'No se puede eliminar el producto porque tiene registros asociados: '.implode('; ', $blocks).'. '
                .'Borrarlo dejaría el sistema inconsistente.',
            ],
        ]);
    }
}
