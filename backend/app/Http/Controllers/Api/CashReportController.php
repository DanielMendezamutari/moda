<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashReportController extends Controller
{
    public function sessions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashRegisterSession::class);

        $q = CashRegisterSession::query()
            ->with(['cashRegister:id,name,branch_id', 'openedBy:id,name', 'closedBy:id,name']);

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return response()->json(['data' => []]);
            }
            $q->whereHas('cashRegister', fn ($w) => $w->where('branch_id', (int) $bid));
        }

        if ($request->filled('from')) {
            $q->where('opened_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $q->where('opened_at', '<=', $request->date('to')->endOfDay());
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }

        $rows = $q->orderByDesc('opened_at')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(static function (CashRegisterSession $s): array {
                return [
                    'id' => $s->id,
                    'status' => $s->status,
                    'opened_at' => $s->opened_at?->toIso8601String(),
                    'closed_at' => $s->closed_at?->toIso8601String(),
                    'opening_float' => (string) $s->opening_float,
                    'expected_cash' => $s->expected_cash !== null ? (string) $s->expected_cash : null,
                    'counted_cash' => $s->counted_cash !== null ? (string) $s->counted_cash : null,
                    'difference_amount' => $s->difference_amount !== null ? (string) $s->difference_amount : null,
                    'cash_register' => $s->cashRegister ? ['id' => $s->cashRegister->id, 'name' => $s->cashRegister->name] : null,
                    'opened_by' => $s->openedBy ? ['name' => $s->openedBy->name] : null,
                ];
            }),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashMovement::class);

        $q = CashMovement::query()->with(['session.cashRegister']);

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return response()->json(['data' => []]);
            }
            $q->whereHas('session.cashRegister', fn ($w) => $w->where('branch_id', (int) $bid));
        }

        if ($request->filled('from')) {
            $q->where('occurred_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $q->where('occurred_at', '<=', $request->date('to')->endOfDay());
        }

        $rows = $q->orderByDesc('occurred_at')->limit(500)->get();

        return response()->json([
            'data' => $rows->map(static function (CashMovement $m): array {
                return [
                    'id' => $m->id,
                    'type' => $m->type,
                    'source' => $m->source,
                    'amount' => (string) $m->amount,
                    'method_payment' => $m->method_payment,
                    'description' => $m->description,
                    'occurred_at' => $m->occurred_at?->toIso8601String(),
                    'session_id' => $m->cash_register_session_id,
                    'cash_register' => $m->session?->cashRegister ? $m->session->cashRegister->name : null,
                ];
            }),
        ]);
    }

    /**
     * Ventas con sesión de caja agrupadas por día (total facturado).
     */
    public function salesByDay(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $q = Sale::query()
            ->whereNotNull('cash_register_session_id')
            ->where('state_sale', '!=', 'cancelled');

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return response()->json(['data' => []]);
            }
            $q->whereHas('items.warehouse', fn ($w) => $w->where('branch_id', (int) $bid));
        }

        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->date('to')->endOfDay());
        }

        $rows = $q->select([
            DB::raw('DATE(created_at) as day'),
            DB::raw('SUM(total) as total_sales'),
            DB::raw('COUNT(*) as sales_count'),
        ])
            ->groupBy('day')
            ->orderByDesc('day')
            ->limit(120)
            ->get();

        return response()->json([
            'data' => $rows->map(static function ($r): array {
                return [
                    'date' => $r->day,
                    'total_sales' => (string) $r->total_sales,
                    'sales_count' => (int) $r->sales_count,
                ];
            }),
        ]);
    }
}
