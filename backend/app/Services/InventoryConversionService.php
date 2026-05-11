<?php

namespace App\Services;

use App\Models\Conversion;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conversión de stock del mismo producto entre unidades de medida en un almacén.
 * El stock en `product_warehouses` se interpreta en la unidad del pivot (`unit_id`), obligatoria.
 */
final class InventoryConversionService
{
    public function __construct(
        private readonly UnitConversionService $unitConversion,
        private readonly InventoryValuationService $valuation,
    ) {}

    /**
     * @param  array{
     *     product_id: int,
     *     warehouse_id: int,
     *     unit_start_id: int,
     *     unit_end_id: int,
     *     quantity_start: float|string,
     *     quantity_end: float|string,
     *     description?: string|null
     * }  $payload
     */
    public function record(User $user, array $payload): Conversion
    {
        $warehouseId = (int) $payload['warehouse_id'];
        $this->assertWarehouseScope($user, $warehouseId);

        $productId = (int) $payload['product_id'];
        $unitStartId = (int) $payload['unit_start_id'];
        $unitEndId = (int) $payload['unit_end_id'];

        if ($unitStartId === $unitEndId) {
            throw ValidationException::withMessages([
                'unit_end_id' => ['La unidad de destino debe ser distinta a la de origen.'],
            ]);
        }

        $qtyStart = (string) $payload['quantity_start'];
        $qtyEnd = (string) $payload['quantity_end'];

        if ((float) $qtyStart <= 0 || (float) $qtyEnd <= 0) {
            throw ValidationException::withMessages([
                'quantity_start' => ['Las cantidades deben ser mayores a cero.'],
            ]);
        }

        return DB::transaction(function () use ($user, $payload, $warehouseId, $productId, $unitStartId, $unitEndId, $qtyStart, $qtyEnd): Conversion {
            Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['El producto no está asignado a este almacén.'],
                ]);
            }

            if ($pivot->unit_id === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['Asigná primero la unidad de stock del producto en este almacén (catálogo / producto–almacén).'],
                ]);
            }

            $stockUnitId = (int) $pivot->unit_id;

            $toStockStart = $this->unitConversion->convertQuantity($qtyStart, $unitStartId, $stockUnitId);
            if ($toStockStart === null) {
                throw ValidationException::withMessages([
                    'unit_start_id' => ['No hay factor de conversión entre la unidad de origen y la unidad de stock del almacén. Configurá conversiones en Unidades.'],
                ]);
            }

            $toStockEnd = $this->unitConversion->convertQuantity($qtyEnd, $unitEndId, $stockUnitId);
            if ($toStockEnd === null) {
                throw ValidationException::withMessages([
                    'unit_end_id' => ['No hay factor de conversión entre la unidad de destino y la unidad de stock del almacén.'],
                ]);
            }

            $startPieces = (float) $toStockStart['quantity_to'];
            $endPieces = (float) $toStockEnd['quantity_to'];
            $deltaFloat = $endPieces - $startPieces;
            $deltaInt = (int) round($deltaFloat);

            if (abs($deltaFloat - $deltaInt) > 0.0001) {
                throw ValidationException::withMessages([
                    'quantity_end' => ['El cambio neto de stock debe ser un número entero de unidades de inventario. Ajustá cantidades o factores de conversión.'],
                ]);
            }

            $currentStock = (int) $pivot->stock;
            if ($currentStock + $deltaInt < 0) {
                throw ValidationException::withMessages([
                    'quantity_start' => ['Stock insuficiente para esta conversión (stock actual: '.$currentStock.').'],
                ]);
            }

            $q0 = $currentStock;
            $c0 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
                ? (float) $pivot->weighted_avg_cost
                : null;

            DB::table('product_warehouses')
                ->where('id', $pivot->id)
                ->update([
                    'stock' => $currentStock + $deltaInt,
                    'updated_at' => now(),
                ]);

            $conversion = new Conversion;
            $conversion->product_id = $productId;
            $conversion->warehouse_id = $warehouseId;
            $conversion->unit_start_id = $unitStartId;
            $conversion->unit_end_id = $unitEndId;
            $conversion->user_id = (int) $user->id;
            $conversion->quantity_start = round((float) $qtyStart, 4);
            $conversion->quantity_end = round((float) $qtyEnd, 4);
            $conversion->stock_delta = $deltaInt;
            $conversion->description = $payload['description'] ?? null;
            $conversion->save();

            $pivotAfter = DB::table('product_warehouses')
                ->where('id', $pivot->id)
                ->first();

            if ($pivotAfter !== null) {
                $this->valuation->applyConversionAfterStockChange(
                    $pivotAfter,
                    $conversion,
                    $q0,
                    $c0,
                    $deltaInt,
                    (int) $user->id,
                );
            }

            return $conversion->fresh()->load([
                'product:id,name,sku',
                'warehouse:id,name,branch_id',
                'unitStart:id,name',
                'unitEnd:id,name',
                'user:id,name',
            ]);
        });
    }

    private function assertWarehouseScope(User $user, int $warehouseId): void
    {
        if ($user->hasRole('admin')) {
            return;
        }

        $bid = $user->branch_id;
        if ($bid === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['Tu usuario no tiene sucursal asignada.'],
            ]);
        }

        $w = Warehouse::query()->find($warehouseId);
        if (! $w || (int) $w->branch_id !== (int) $bid) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['No podés convertir stock en un almacén de otra sucursal.'],
            ]);
        }
    }
}
