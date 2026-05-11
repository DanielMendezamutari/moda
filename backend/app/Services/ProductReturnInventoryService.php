<?php

namespace App\Services;

use App\Models\ProductReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajuste de stock al cerrar devoluciones: estado `reparado` ingresa existencias;
 * si se revierte desde `reparado`, descuenta lo mismo una sola vez.
 */
final class ProductReturnInventoryService
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
    ) {}

    public function syncAfterStateChange(ProductReturn $return, ?string $previousState): void
    {
        $prev = $previousState ?? '';
        $next = (string) $return->state;

        $wasReparado = $prev === ProductReturn::STATE_REPARADO;
        $isReparado = $next === ProductReturn::STATE_REPARADO;

        if ($wasReparado === $isReparado) {
            return;
        }

        if ($isReparado) {
            $this->applyInboundStock($return);

            return;
        }

        if ($wasReparado && $return->inventory_applied_at !== null) {
            $this->revertInboundStock($return);
        }
    }

    private function applyInboundStock(ProductReturn $return): void
    {
        if ($return->inventory_applied_at !== null) {
            return;
        }

        $qtyInt = $this->integerQuantity($return);

        DB::transaction(function () use ($return, $qtyInt): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', (int) $return->product_id)
                ->where('warehouse_id', (int) $return->warehouse_id)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['No existe relación producto–almacén para ingresar el stock devuelto.'],
                ]);
            }

            $this->valuation->applyReturnInbound($pivot, $return);

            $return->forceFill(['inventory_applied_at' => now()])->save();
        });
    }

    private function revertInboundStock(ProductReturn $return): void
    {
        $qtyInt = $this->integerQuantity($return);

        DB::transaction(function () use ($return, $qtyInt): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', (int) $return->product_id)
                ->where('warehouse_id', (int) $return->warehouse_id)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['No se pudo revertir stock: falta fila producto–almacén.'],
                ]);
            }

            if ((int) $pivot->stock < $qtyInt) {
                throw ValidationException::withMessages([
                    'state' => ['No se puede revertir: stock actual ('.(int) $pivot->stock.') es menor que la cantidad devuelta ('.$qtyInt.').'],
                ]);
            }

            $this->valuation->revertReturnInbound($pivot, $return);

            $return->forceFill(['inventory_applied_at' => null])->save();
        });
    }

    private function integerQuantity(ProductReturn $return): int
    {
        $q = (float) $return->quantity;
        $n = (int) round($q);
        if ($n <= 0 || abs($q - $n) > 0.0001) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad de devolución debe ser un entero positivo (unidades).'],
            ]);
        }

        return $n;
    }
}
