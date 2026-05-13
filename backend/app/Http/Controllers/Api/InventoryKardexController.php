<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryKardexEntry;
use App\Models\Product;
use App\Models\ProductStockInitial;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryKardexController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->assertCanViewKardex($request);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $product = Product::query()->findOrFail((int) $validated['product_id']);
        $this->authorize('view', $product);
        $warehouseId = (int) $validated['warehouse_id'];

        $ledger = $this->buildWarehouseLedger(
            (int) $product->id,
            $warehouseId,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        return response()->json([
            'data' => array_merge(
                [
                    'product' => $this->productKardexSnippet($product),
                    'warehouse_id' => $warehouseId,
                ],
                $ledger,
            ),
        ]);
    }

    /**
     * Kardex del producto en todos los almacenes (inventario con lector de código).
     * Acepta `barcode` o `product_id`.
     */
    public function productLedger(Request $request): JsonResponse
    {
        $this->assertCanViewKardex($request);

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $hasPid = isset($validated['product_id']);
        $barcodeRaw = isset($validated['barcode']) ? trim((string) $validated['barcode']) : '';

        if (! $hasPid && $barcodeRaw === '') {
            throw ValidationException::withMessages([
                'barcode' => ['Indicá el código de barras o el producto.'],
            ]);
        }

        $product = $this->resolveProductForLedger($validated, $barcodeRaw, $hasPid);
        $this->authorize('view', $product);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        $warehouseIds = $this->warehouseIdsForProductLedger((int) $product->id);

        $warehouses = [];
        foreach ($warehouseIds as $wid) {
            $w = Warehouse::query()->with(['branch:id,name,code'])->find($wid);
            if ($w === null) {
                continue;
            }
            $ledger = $this->buildWarehouseLedger((int) $product->id, $wid, $from, $to);
            $warehouses[] = [
                'warehouse_id' => $w->id,
                'warehouse' => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'branch' => $w->branch ? [
                        'id' => $w->branch->id,
                        'name' => $w->branch->name,
                        'code' => $w->branch->code,
                    ] : null,
                ],
                'summary' => $ledger['summary'],
                'sections' => $ledger['sections'],
            ];
        }

        return response()->json([
            'data' => [
                'product' => $this->productKardexSnippet($product),
                'from' => $from,
                'to' => $to,
                'view_types' => [
                    [
                        'id' => 'valorizado',
                        'title' => 'Kardex valorizado (completo)',
                        'description' => 'Todos los movimientos con entradas, salidas y existencias valorizadas por almacén.',
                    ],
                    [
                        'id' => 'ventas',
                        'title' => 'Kardex por ventas',
                        'description' => 'Solo salidas por despacho de ventas (afectan stock).',
                    ],
                    [
                        'id' => 'compras',
                        'title' => 'Kardex por compras',
                        'description' => 'Ingresos y reversiones ligadas a compras entregadas.',
                    ],
                    [
                        'id' => 'logistica',
                        'title' => 'Traslados, devoluciones y conversiones',
                        'description' => 'Movimientos entre almacenes, devoluciones de clientes y conversiones de unidad.',
                    ],
                ],
                'warehouses' => $warehouses,
            ],
        ]);
    }

    private function assertCanViewKardex(Request $request): void
    {
        if (! $request->user()->can('inventory.kardex.view') && ! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveProductForLedger(array $validated, string $barcodeRaw, bool $hasPid): Product
    {
        if ($hasPid) {
            return Product::query()->findOrFail((int) $validated['product_id']);
        }

        $needle = mb_strtolower($barcodeRaw, 'UTF-8');

        $product = Product::query()
            ->where(static function ($q) use ($needle): void {
                $q->whereRaw('LOWER(TRIM(barcode)) = ?', [$needle])
                    ->orWhereRaw('LOWER(TRIM(sku)) = ?', [$needle]);
            })
            ->first();

        if ($product === null) {
            abort(404, 'No se encontró un producto con ese código de barras o SKU.');
        }

        return $product;
    }

    /**
     * @return list<int>
     */
    private function warehouseIdsForProductLedger(int $productId): array
    {
        $fromPivot = DB::table('product_warehouses')
            ->where('product_id', $productId)
            ->pluck('warehouse_id');

        $fromKardex = InventoryKardexEntry::query()
            ->where('product_id', $productId)
            ->distinct()
            ->pluck('warehouse_id');

        $ids = $fromPivot->merge($fromKardex)->unique()->filter()->sort()->values()->all();

        return array_map(static fn ($id): int => (int) $id, $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function productKardexSnippet(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, sections: array<int, array<string, mixed>>}
     */
    private function buildWarehouseLedger(int $productId, int $warehouseId, ?string $from, ?string $to): array
    {
        $pivot = DB::table('product_warehouses')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $unitId = $pivot?->unit_id !== null ? (int) $pivot->unit_id : null;
        $unitName = $unitId ? (Unit::query()->whereKey($unitId)->value('name') ?? 'UNIDAD') : 'UNIDAD';

        $q = InventoryKardexEntry::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($from, fn ($q2, $d) => $q2->whereDate('occurred_at', '>=', $d))
            ->when($to, fn ($q2, $d) => $q2->whereDate('occurred_at', '<=', $d))
            ->orderBy('occurred_at')
            ->orderBy('id');

        $rows = $q->get()->map(fn (InventoryKardexEntry $e) => $this->serializeEntry($e));

        $initial = ProductStockInitial::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $initialRow = null;
        if ($initial && ($initial->stock > 0 || $initial->price_unit_avg !== null)) {
            $st = (int) $initial->stock;
            $pu = $initial->price_unit_avg !== null ? (string) $initial->price_unit_avg : null;
            $tot = ($st > 0 && $pu !== null) ? round((float) $pu * $st, 2) : null;
            $iu = $initial->unit_id ?? $unitId;
            $in = $iu ? (Unit::query()->whereKey($iu)->value('name') ?? $unitName) : $unitName;
            $initialRow = [
                'id' => null,
                'occurred_at' => null,
                'detail_label' => 'INICIAL',
                'movement_type' => 'initial',
                'in_qty' => $st > 0 ? $st : null,
                'in_unit_value' => $pu !== null ? (float) $pu : null,
                'in_total_value' => $tot,
                'out_qty' => null,
                'out_unit_value' => null,
                'out_total_value' => null,
                'balance_quantity' => $st,
                'balance_avg_cost' => $pu !== null ? (float) $pu : null,
                'balance_total_value' => $tot,
                'unit' => ['id' => $iu, 'name' => $in],
            ];
        }

        $list = collect();
        if ($initialRow !== null) {
            $list->push($initialRow);
        }
        $list = $list->merge($rows)->values()->all();

        $sections = [[
            'unit' => ['id' => $unitId, 'name' => mb_strtoupper($unitName, 'UTF-8')],
            'initial_row' => $initialRow,
            'rows' => $list,
        ]];

        $wac = $pivot && isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? (float) $pivot->weighted_avg_cost
            : null;
        $stk = $pivot ? (int) $pivot->stock : 0;
        $invTotal = ($wac !== null && $stk > 0) ? round($wac * $stk, 2) : null;

        $summary = [
            'valuation_method' => 'weighted_average',
            'stock_quantity' => $stk,
            'weighted_avg_cost' => $wac,
            'inventory_total_value' => $invTotal,
            'movement_sources' => [
                'purchase_items' => 'COMPRAS (línea entregada)',
                'sale_detail' => 'DESPACHO (venta)',
                'product_return' => 'DEVOLUCIÓN',
                'transport_detail' => 'TRASLADO salida / entrada entre almacenes',
                'conversion' => 'CONVERSIÓN de unidad',
                'product_stock_initials' => 'INICIAL (apertura)',
            ],
        ];

        return [
            'summary' => $summary,
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(InventoryKardexEntry $e): array
    {
        $e->loadMissing('unit:id,name');

        return [
            'id' => $e->id,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'detail_label' => $e->detail_label,
            'movement_type' => $e->movement_type,
            'reference_type' => $e->reference_type,
            'reference_id' => $e->reference_id,
            'in_qty' => $e->in_qty !== null ? (float) $e->in_qty : null,
            'in_unit_value' => $e->in_unit_value !== null ? (float) $e->in_unit_value : null,
            'in_total_value' => $e->in_total_value !== null ? (float) $e->in_total_value : null,
            'out_qty' => $e->out_qty !== null ? (float) $e->out_qty : null,
            'out_unit_value' => $e->out_unit_value !== null ? (float) $e->out_unit_value : null,
            'out_total_value' => $e->out_total_value !== null ? (float) $e->out_total_value : null,
            'balance_quantity' => (int) $e->balance_quantity,
            'balance_avg_cost' => $e->balance_avg_cost !== null ? (float) $e->balance_avg_cost : null,
            'balance_total_value' => $e->balance_total_value !== null ? (float) $e->balance_total_value : null,
            'unit' => $e->unit ? ['id' => $e->unit->id, 'name' => $e->unit->name] : null,
        ];
    }
}
