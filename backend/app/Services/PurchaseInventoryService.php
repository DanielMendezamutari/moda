<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Ingreso de stock al marcar línea de compra como entregada; reverso si vuelve a solicitud.
 */
final class PurchaseInventoryService
{
    public function __construct(
        private readonly UnitConversionService $conversionService,
        private readonly InventoryValuationService $valuation,
    ) {}

    /**
     * Cantidad en unidades de stock del pivot para una línea de compra (misma lógica que al recibir mercadería).
     *
     * @throws ValidationException
     */
    public function purchaseLineDeltaForWarehouse(PurchaseItem $item, Purchase $purchase): int
    {
        $warehouseId = (int) $purchase->warehouse_id;
        $productId = (int) $item->product_id;

        $pivot = DB::table('product_warehouses')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($pivot === null) {
            throw ValidationException::withMessages([
                'product_id' => ['El producto no está asociado a este almacén.'],
            ]);
        }

        return $this->stockDeltaInPivotUnits($item, $pivot);
    }

    public function syncAfterLineStateChange(PurchaseItem $item, ?string $previousState, Purchase $purchase): void
    {
        $prev = $previousState ?? '';
        $next = (string) $item->state;

        $wasEntregado = $prev === PurchaseItem::STATE_ENTREGADO;
        $isEntregado = $next === PurchaseItem::STATE_ENTREGADO;

        if ($wasEntregado === $isEntregado) {
            return;
        }

        if ($isEntregado) {
            $this->applyInboundStock($item, $purchase);

            return;
        }

        if ($wasEntregado && $item->inventory_received_at !== null) {
            $this->revertInboundStock($item, $purchase);
        }
    }

    private function applyInboundStock(PurchaseItem $item, Purchase $purchase): void
    {
        if ($item->inventory_received_at !== null) {
            return;
        }

        $warehouseId = (int) $purchase->warehouse_id;
        $productId = (int) $item->product_id;

        DB::transaction(function () use ($item, $purchase, $warehouseId, $productId): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['El producto no está asociado a este almacén. Asignalo en el catálogo antes de recibir la compra.'],
                ]);
            }

            $delta = $this->stockDeltaInPivotUnits($item, $pivot);

            $this->valuation->applyPurchaseInbound($pivot, $item, $purchase, $delta);

            $item->forceFill(['inventory_received_at' => now()])->save();
        });
    }

    private function revertInboundStock(PurchaseItem $item, Purchase $purchase): void
    {
        $warehouseId = (int) $purchase->warehouse_id;
        $productId = (int) $item->product_id;

        DB::transaction(function () use ($item, $purchase, $warehouseId, $productId): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['No se pudo revertir stock: falta fila producto–almacén.'],
                ]);
            }

            $delta = $this->stockDeltaInPivotUnits($item, $pivot);

            if ((int) $pivot->stock < $delta) {
                throw ValidationException::withMessages([
                    'state' => ['No se puede revertir: stock actual ('.(int) $pivot->stock.') es menor que la cantidad recibida ('.$delta.').'],
                ]);
            }

            $this->valuation->revertPurchaseInbound($pivot, $item, $purchase, $delta);

            $item->forceFill(['inventory_received_at' => null])->save();
        });
    }

    /**
     * Cantidad a sumar o restar en la unidad de inventario del pivot (product_warehouses.unit_id).
     * Si la compra viene en otra unidad (docena, caja…), convierte usando unit_conversions.
     */
    private function stockDeltaInPivotUnits(PurchaseItem $item, stdClass $pivot): int
    {
        $q = (float) $item->quantity;
        $qtyPurchaseInt = (int) round($q);
        if ($qtyPurchaseInt <= 0 || abs($q - $qtyPurchaseInt) > 0.0001) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad de compra debe ser un entero positivo.'],
            ]);
        }

        $stockUnitId = $pivot->unit_id !== null ? (int) $pivot->unit_id : null;
        $purchaseUnitId = $item->unit_id !== null ? (int) $item->unit_id : null;

        if ($stockUnitId === null) {
            return $qtyPurchaseInt;
        }

        if ($purchaseUnitId === null || $purchaseUnitId === $stockUnitId) {
            return $qtyPurchaseInt;
        }

        $result = $this->conversionService->convertQuantity((string) $qtyPurchaseInt, $purchaseUnitId, $stockUnitId);
        if ($result === null) {
            throw ValidationException::withMessages([
                'unit_id' => ['No hay conversión entre la unidad de la línea de compra y la unidad de stock del almacén. Configurá conversiones en Unidades o usá la misma unidad.'],
            ]);
        }

        $asFloat = (float) $result['quantity_to'];
        $delta = (int) round($asFloat);
        if ($delta < 0) {
            throw ValidationException::withMessages([
                'quantity' => ['La conversión a unidad de stock no puede ser negativa.'],
            ]);
        }

        if ($delta === 0 && $qtyPurchaseInt > 0) {
            throw ValidationException::withMessages([
                'quantity' => ['La conversión a unidad de stock dio cero; revisá factores y unidades.'],
            ]);
        }

        return $delta;
    }
}
