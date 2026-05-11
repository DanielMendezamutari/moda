<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Resumen para el panel: ventas por día (14 días) y productos más vendidos (cantidad).
     * Misma visibilidad de sucursal que el listado de ventas (`SalePolicy::viewAny`).
     */
    public function analytics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $user = $request->user();
        $isAdmin = $user->hasRole('admin');
        $branchId = $user->branch_id;

        if (! $isAdmin && $branchId === null) {
            return response()->json([
                'data' => [
                    'sales_by_day' => [],
                    'top_products' => [],
                    'range_days' => 14,
                ],
            ]);
        }

        $scopedSales = Sale::query()
            ->where('state_sale', '!=', 'cancelled')
            ->when(! $isAdmin, function ($q) use ($branchId): void {
                $q->whereHas('items.warehouse', fn ($w) => $w->where('branch_id', (int) $branchId));
            });

        $from = now()->subDays(13)->startOfDay();

        $dailyRows = (clone $scopedSales)
            ->where('created_at', '>=', $from)
            ->select([
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('COUNT(*) as sales_count'),
            ])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $daily = $dailyRows->mapWithKeys(function ($r): array {
            $key = Carbon::parse($r->day)->toDateString();

            return [$key => $r];
        });

        $salesByDay = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $row = $daily->get($d);
            $salesByDay[] = [
                'date' => $d,
                'total_sales' => $row ? (string) $row->total_sales : '0',
                'sales_count' => $row ? (int) $row->sales_count : 0,
            ];
        }

        $saleIdsSub = Sale::query()
            ->select('id')
            ->where('state_sale', '!=', 'cancelled')
            ->when(! $isAdmin, function ($q) use ($branchId): void {
                $q->whereHas('items.warehouse', fn ($w) => $w->where('branch_id', (int) $branchId));
            });

        $topRows = SaleDetail::query()
            ->whereIn('sale_id', $saleIdsSub)
            ->select([
                'sale_details.product_id',
                DB::raw('SUM(sale_details.quantity) as qty_sold'),
                DB::raw('SUM(sale_details.line_total) as revenue'),
            ])
            ->groupBy('sale_details.product_id')
            ->orderByDesc(DB::raw('SUM(sale_details.quantity)'))
            ->limit(10)
            ->get();

        $productIds = $topRows->pluck('product_id')->filter()->unique()->values()->all();
        $names = $productIds === []
            ? collect()
            : Product::query()->whereIn('id', $productIds)->pluck('name', 'id');

        $topProducts = $topRows->map(static function ($r) use ($names): array {
            $pid = (int) $r->product_id;

            return [
                'product_id' => $pid,
                'name' => (string) ($names[$pid] ?? ('#'.$pid)),
                'qty_sold' => (string) $r->qty_sold,
                'revenue' => (string) $r->revenue,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'sales_by_day' => $salesByDay,
                'top_products' => $topProducts,
                'range_days' => 14,
            ],
        ]);
    }
}
