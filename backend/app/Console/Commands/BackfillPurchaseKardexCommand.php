<?php

namespace App\Console\Commands;

use App\Models\InventoryKardexEntry;
use App\Models\ProductStockInitial;
use App\Models\PurchaseItem;
use App\Services\InventoryValuationService;
use App\Services\PurchaseInventoryService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea filas COMPRAS en el kardex para líneas ya marcadas como entregadas que no tienen registro
 * (p. ej. tabla `inventory_kardex_entries` creada después o error al guardar).
 *
 * No modifica stock ni `inventory_received_at`. Recalcula saldos en cadena por producto–almacén
 * en orden cronológico; omite líneas cuya fecha sea anterior al último movimiento ya registrado en el kardex.
 */
class BackfillPurchaseKardexCommand extends Command
{
    protected $signature = 'inventory:backfill-purchase-kardex
                            {--dry-run : Solo muestra qué haría, sin insertar}
                            {--limit= : Máximo de líneas a procesar (número)}';

    protected $description = 'Registra en el kardex compras entregadas que aún no tienen fila COMPRAS';

    public function handle(
        PurchaseInventoryService $purchaseInventory,
        InventoryValuationService $valuation,
    ): int {
        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit');
        $limitN = $limit !== null && $limit !== '' ? max(1, (int) $limit) : null;

        $q = PurchaseItem::query()
            ->with(['purchase'])
            ->where('purchase_items.state', PurchaseItem::STATE_ENTREGADO)
            ->whereNotNull('purchase_items.inventory_received_at')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw('1'))
                    ->from('inventory_kardex_entries as ike')
                    ->whereColumn('ike.reference_id', 'purchase_items.id')
                    ->where('ike.reference_type', 'purchase_item')
                    ->where('ike.movement_type', InventoryValuationService::MOV_PURCHASE_IN);
            })
            ->orderByRaw('COALESCE(purchase_items.date_entrega, purchase_items.inventory_received_at)')
            ->orderBy('purchase_items.id')
            ->select('purchase_items.*');

        $items = $limitN !== null ? $q->limit($limitN)->get() : $q->get();

        if ($items->isEmpty()) {
            $this->info('No hay líneas de compra entregadas pendientes de kardex.');

            return self::SUCCESS;
        }

        $this->info('Líneas a procesar: '.$items->count().($dry ? ' (dry-run)' : ''));

        $inserted = 0;
        $skipped = 0;

        foreach ($items as $item) {
            /** @var PurchaseItem $item */
            $purchase = $item->purchase;
            if ($purchase === null) {
                $this->warn("Saltando item {$item->id}: compra inexistente.");
                $skipped++;

                continue;
            }

            $productId = (int) $item->product_id;
            $warehouseId = (int) $purchase->warehouse_id;

            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            if ($pivot === null) {
                $this->warn("Saltando item {$item->id}: sin pivot producto–almacén.");
                $skipped++;

                continue;
            }

            try {
                $delta = $purchaseInventory->purchaseLineDeltaForWarehouse($item, $purchase);
            } catch (\Throwable $e) {
                $this->warn("Saltando item {$item->id}: {$e->getMessage()}");
                $skipped++;

                continue;
            }

            $occurredAt = $valuation->purchaseInboundOccurredAt($item);

            $last = InventoryKardexEntry::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first();

            if ($last !== null && Carbon::parse($last->occurred_at)->gt($occurredAt)) {
                $this->line("Omitido item {$item->id}: fecha movimiento anterior al último kardex (reordenar a mano o contactar soporte).");
                $skipped++;

                continue;
            }

            if ($last !== null) {
                $q0 = (int) $last->balance_quantity;
                $c0 = $last->balance_avg_cost !== null ? (float) $last->balance_avg_cost : null;
            } else {
                $initial = ProductStockInitial::query()
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();
                $q0 = $initial !== null ? (int) $initial->stock : 0;
                $c0 = $initial !== null && $initial->price_unit_avg !== null
                    ? (float) $initial->price_unit_avg
                    : null;
            }

            $this->line("Item {$item->id} producto {$productId} almacén {$warehouseId} delta {$delta} @ {$occurredAt->toDateTimeString()}");

            if (! $dry) {
                DB::transaction(function () use ($valuation, $pivot, $item, $purchase, $delta, $q0, $c0, $occurredAt): void {
                    $valuation->insertPurchaseInboundKardexOnly($pivot, $item, $purchase, $delta, $q0, $c0, $occurredAt);
                });
            }
            $inserted++;
        }

        $this->info($dry ? "Dry-run: se insertarían {$inserted} filas; omitidas {$skipped}." : "Insertadas {$inserted} filas; omitidas {$skipped}.");

        return self::SUCCESS;
    }
}
