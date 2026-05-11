<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryKardexEntry;
use App\Models\Product;
use App\Models\ProductStockInitial;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryKardexController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('inventory.kardex.view') && ! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $productId = (int) $validated['product_id'];
        $warehouseId = (int) $validated['warehouse_id'];

        $product = Product::query()->findOrFail($productId);
        $this->authorize('view', $product);

        $pivot = DB::table('product_warehouses')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $unitId = $pivot?->unit_id !== null ? (int) $pivot->unit_id : null;
        $unitName = $unitId ? (Unit::query()->whereKey($unitId)->value('name') ?? 'UNIDAD') : 'UNIDAD';

        $q = InventoryKardexEntry::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($validated['from'] ?? null, fn ($q2, $d) => $q2->whereDate('occurred_at', '>=', $d))
            ->when($validated['to'] ?? null, fn ($q2, $d) => $q2->whereDate('occurred_at', '<=', $d))
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
        if ($initialRow !== null)
            $list->push($initialRow);
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

        return response()->json([
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ],
                'warehouse_id' => $warehouseId,
                'summary' => [
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
                ],
                'sections' => $sections,
            ],
        ]);
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
