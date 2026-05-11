<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private readonly PurchaseInventoryService $inventory,
    ) {}

    /**
     * @param  array{
     *     warehouse_id: int,
     *     supplier_id?: int|null,
     *     date_emision?: string|null,
     *     state?: string,
     *     type_comprobant?: string|null,
     *     n_comprobant?: string|null,
     *     reference?: string|null,
     *     description?: string|null,
     *     notes?: string|null,
     *     requester_id: int,
     *     igv?: float|string,
     *     date_entrega?: string|null,
     *     items: list<array{product_id: int, quantity: float|string, price_unit: float|string, unit_id?: int|null, description?: string|null, update_sale_price?: bool, new_sale_price?: float|string|null}>
     * }  $payload
     */
    public function createPurchase(User $user, array $payload): Purchase
    {
        $items = $payload['items'];
        $warehouseId = (int) $payload['warehouse_id'];
        $this->assertWarehouseScope($user, $warehouseId);

        $requesterId = (int) ($payload['requester_id'] ?? 0);
        if ($requesterId <= 0) {
            throw ValidationException::withMessages([
                'requester_id' => ['Indicá el solicitante de la compra.'],
            ]);
        }
        User::query()->whereKey($requesterId)->firstOrFail();

        return DB::transaction(function () use ($user, $payload, $items, $warehouseId, $requesterId): Purchase {
            $lineTotals = [];
            $prepared = [];

            foreach ($items as $idx => $line) {
                $product = Product::query()->lockForUpdate()->findOrFail((int) $line['product_id']);
                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.product_id" => ['El producto no está activo.'],
                    ]);
                }

                $qty = (float) $line['quantity'];
                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => ['La cantidad debe ser mayor a cero.'],
                    ]);
                }
                $qtyInt = (int) round($qty);
                if (abs($qty - $qtyInt) > 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => ['La cantidad debe ser un número entero.'],
                    ]);
                }

                $priceUnit = round((float) $line['price_unit'], 2);
                if ($priceUnit < 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.price_unit" => ['El precio unitario no puede ser negativo.'],
                    ]);
                }

                $pivot = DB::table('product_warehouses')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                if ($pivot === null) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.product_id" => ['El producto no está asignado a este almacén.'],
                    ]);
                }

                $lineTotal = round($qtyInt * $priceUnit, 2);
                $lineTotals[] = $lineTotal;

                $unitId = isset($line['unit_id']) ? ($line['unit_id'] !== null ? (int) $line['unit_id'] : null) : ($pivot->unit_id !== null ? (int) $pivot->unit_id : null);

                $updateSale = ! empty($line['update_sale_price'])
                    && array_key_exists('new_sale_price', $line)
                    && $line['new_sale_price'] !== null
                    && $line['new_sale_price'] !== '';
                if ($updateSale) {
                    $newSale = round((float) $line['new_sale_price'], 2);
                    if ($newSale < 0) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.new_sale_price" => ['El precio de venta no puede ser negativo.'],
                        ]);
                    }
                    $product->price = $newSale;
                    $product->save();
                    DB::table('product_warehouses')
                        ->where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->update([
                            'sale_price' => $newSale,
                            'updated_at' => now(),
                        ]);
                }

                $prepared[] = [
                    'product_id' => $product->id,
                    'unit_id' => $unitId,
                    'quantity' => $qtyInt,
                    'unit_cost' => $priceUnit,
                    'line_total' => $lineTotal,
                    'state' => PurchaseItem::STATE_SOLICITUD,
                    'description' => isset($line['description']) ? (string) $line['description'] : null,
                ];
            }

            $importe = round(array_sum($lineTotals), 2);
            $igv = round((float) ($payload['igv'] ?? 0), 2);
            if ($igv < 0) {
                throw ValidationException::withMessages(['igv' => ['El IGV no puede ser negativo.']]);
            }
            $total = round($importe + $igv, 2);

            $purchase = new Purchase;
            $purchase->warehouse_id = $warehouseId;
            $purchase->user_id = $requesterId;
            $purchase->supplier_id = isset($payload['supplier_id']) && $payload['supplier_id'] !== null ? (int) $payload['supplier_id'] : null;
            $purchase->date_emision = isset($payload['date_emision']) ? $payload['date_emision'] : now()->toDateString();
            $purchase->state = isset($payload['state']) ? (string) $payload['state'] : Purchase::STATE_SOLICITUD;
            $purchase->type_comprobant = $payload['type_comprobant'] ?? null;
            $purchase->n_comprobant = $payload['n_comprobant'] ?? null;
            $purchase->reference = $payload['reference'] ?? null;
            $purchase->description = $payload['description'] ?? null;
            $purchase->notes = $payload['notes'] ?? null;
            $purchase->importe = $importe;
            $purchase->igv = $igv;
            $purchase->total = $total;
            $purchase->date_entrega = $payload['date_entrega'] ?? null;
            $purchase->save();

            foreach ($prepared as $row) {
                $purchase->items()->create($row);
            }

            $this->syncHeaderStateFromLines($purchase->fresh('items'));

            return $purchase->fresh()->load([
                'warehouse:id,name,branch_id',
                'user:id,name',
                'supplier:id,name,ruc',
                'items.product:id,name,sku',
                'items.unit:id,name',
            ]);
        });
    }

    public function syncHeaderStateFromLines(Purchase $purchase): void
    {
        $purchase->loadMissing('items');
        if ($purchase->items->isEmpty()) {
            return;
        }

        $allEnt = $purchase->items->every(fn (PurchaseItem $i) => $i->state === PurchaseItem::STATE_ENTREGADO);
        $anyEnt = $purchase->items->contains(fn (PurchaseItem $i) => $i->state === PurchaseItem::STATE_ENTREGADO);

        if ($allEnt) {
            $purchase->state = Purchase::STATE_ENTREGADO;
        } elseif ($anyEnt) {
            $purchase->state = Purchase::STATE_PARCIAL;
        } elseif (in_array($purchase->state, [Purchase::STATE_PARCIAL, Purchase::STATE_ENTREGADO], true)) {
            $purchase->state = Purchase::STATE_REVISION;
        }

        $purchase->save();
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
                'warehouse_id' => ['No podés comprar para un almacén de otra sucursal.'],
            ]);
        }
    }
}
