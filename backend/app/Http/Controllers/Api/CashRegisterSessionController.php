<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Policies\CashRegisterPolicy;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CashRegisterSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashRegisterSession::class);

        $q = CashRegisterSession::query()
            ->with(['cashRegister.branch', 'openedBy:id,name', 'closedBy:id,name'])
            ->orderByDesc('opened_at');

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return response()->json(['data' => []]);
            }
            $q->whereHas('cashRegister', fn ($w) => $w->where('branch_id', (int) $bid));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('from')) {
            $q->where('opened_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $q->where('opened_at', '<=', $request->date('to')->endOfDay());
        }

        $limit = min((int) $request->input('per_page', 30), 100);

        return response()->json([
            'data' => $q->limit($limit)->get()->map(fn (CashRegisterSession $s) => $this->serializeSession($s)),
        ]);
    }

    /**
     * Sesiones abiertas visibles para el usuario (por sucursal).
     */
    public function active(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashRegisterSession::class);

        $q = CashRegisterSession::query()
            ->with(['cashRegister.branch', 'openedBy:id,name'])
            ->where('status', 'open');

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return response()->json(['data' => []]);
            }
            $q->whereHas('cashRegister', fn ($w) => $w->where('branch_id', (int) $bid));
        }

        if ($request->filled('cash_register_id')) {
            $q->where('cash_register_id', (int) $request->input('cash_register_id'));
        }

        return response()->json([
            'data' => $q->get()->map(fn (CashRegisterSession $s) => $this->serializeSession($s)),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->authorize('open', CashRegisterSession::class);

        $data = $request->validate([
            'cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'],
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        $register = CashRegister::query()->findOrFail((int) $data['cash_register_id']);

        $policy = new CashRegisterPolicy;
        if (! $policy->canAccessBranch($request->user(), (int) $register->branch_id)) {
            abort(403, 'No autorizado.');
        }

        if ($register->openSession() !== null) {
            throw ValidationException::withMessages([
                'cash_register_id' => ['Esta caja ya tiene un turno abierto.'],
            ]);
        }

        $session = CashRegisterSession::query()->create([
            'cash_register_id' => $register->id,
            'opened_by_user_id' => $request->user()->id,
            'opened_at' => now(),
            'opening_float' => round((float) $data['opening_float'], 2),
            'status' => 'open',
        ]);

        $session->load(['cashRegister.branch', 'openedBy:id,name']);

        return response()->json(['data' => $this->serializeSession($session)], 201);
    }

    public function close(Request $request, CashRegisterSession $session): JsonResponse
    {
        $this->authorize('close', $session);

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'session' => ['Este turno ya está cerrado.'],
            ]);
        }

        $session->load('movements');

        $expected = $this->expectedCashInDrawer($session);
        $counted = round((float) $data['counted_cash'], 2);

        $session->expected_cash = round($expected, 2);
        $session->counted_cash = $counted;
        $session->difference_amount = round($counted - $expected, 2);
        $session->closed_at = now();
        $session->closed_by_user_id = $request->user()->id;
        $session->status = 'closed';
        $session->notes = $data['notes'] ?? null;
        $session->save();

        $session->load(['cashRegister.branch', 'openedBy:id,name', 'closedBy:id,name']);

        return response()->json(['data' => $this->serializeSession($session)]);
    }

    /**
     * Resumen del turno: ventas, cobros por medio, movimientos manuales, efectivo esperado en cajón.
     */
    public function summary(Request $request, CashRegisterSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        return response()->json(['data' => $this->buildSessionSummary($session)]);
    }

    /**
     * PDF del cierre (o estado parcial si el turno sigue abierto).
     */
    public function closeReport(Request $request, CashRegisterSession $session): Response
    {
        $this->authorize('view', $session);

        $summary = $this->buildSessionSummary($session);
        $session->load(['cashRegister.branch', 'openedBy:id,name', 'closedBy:id,name']);

        $html = view('exports.cash_session_close_pdf', [
            'session' => $session,
            'summary' => $summary,
            'generatedAt' => now()->timezone(config('app.timezone'))->format('d/m/Y H:i:s'),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $suffix = $session->status === 'closed' ? 'cierre' : 'parcial';
        $baseName = 'caja-turno-'.$session->id.'-'.$suffix.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSessionSummary(CashRegisterSession $session): array
    {
        $session->loadMissing(['cashRegister.branch', 'openedBy:id,name', 'closedBy:id,name', 'movements']);

        $salesBase = Sale::query()->where('cash_register_session_id', $session->id)
            ->where('state_sale', '!=', 'cancelled');

        $salesCount = (clone $salesBase)->count();
        $salesTotal = round((float) (clone $salesBase)->sum('total'), 2);

        $paymentRows = DB::table('sale_payments')
            ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
            ->where('sales.cash_register_session_id', $session->id)
            ->where('sales.state_sale', '!=', 'cancelled')
            ->selectRaw('LOWER(TRIM(sale_payments.method_payment)) as method_key')
            ->selectRaw('SUM(sale_payments.amount) as total_amt')
            ->groupBy('method_key')
            ->get();

        $paymentsByMethod = [];
        foreach ($paymentRows as $row) {
            $k = $row->method_key !== '' ? $row->method_key : 'otro';
            $paymentsByMethod[$k] = number_format((float) $row->total_amt, 2, '.', '');
        }

        $expected = $this->expectedCashInDrawer($session);

        $mv = CashMovement::query()->where('cash_register_session_id', $session->id);
        $manualIncome = round((float) (clone $mv)->where('source', 'manual')->where('type', 'income')->sum('amount'), 2);
        $manualExpense = round((float) (clone $mv)->where('source', 'manual')->where('type', 'expense')->sum('amount'), 2);
        $saleMovementsTotal = round((float) (clone $mv)->where('source', 'sale_payment')->where('type', 'income')->sum('amount'), 2);

        return [
            'sales_count' => $salesCount,
            'sales_total' => number_format($salesTotal, 2, '.', ''),
            'payments_by_method' => $paymentsByMethod,
            'opening_float' => (string) $session->opening_float,
            'expected_cash' => number_format($expected, 2, '.', ''),
            'manual_income' => number_format($manualIncome, 2, '.', ''),
            'manual_expense' => number_format($manualExpense, 2, '.', ''),
            'movements_from_sales' => number_format($saleMovementsTotal, 2, '.', ''),
            'status' => $session->status,
            'counted_cash' => $session->counted_cash !== null ? (string) $session->counted_cash : null,
            'difference_amount' => $session->difference_amount !== null ? (string) $session->difference_amount : null,
            'branch_name' => $session->cashRegister?->branch?->name,
            'cash_register_name' => $session->cashRegister?->name,
        ];
    }

    /**
     * Efectivo esperado en cajón: fondo inicial + ingresos efectivo − egresos efectivo (QR u otros no suman al físico).
     */
    private function expectedCashInDrawer(CashRegisterSession $session): float
    {
        $opening = (float) $session->opening_float;

        $in = (float) $session->movements()
            ->where('type', 'income')
            ->whereRaw('LOWER(TRIM(method_payment)) = ?', ['efectivo'])
            ->sum('amount');

        $out = (float) $session->movements()
            ->where('type', 'expense')
            ->whereRaw('LOWER(TRIM(method_payment)) = ?', ['efectivo'])
            ->sum('amount');

        return round($opening + $in - $out, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(CashRegisterSession $s): array
    {
        return [
            'id' => $s->id,
            'cash_register_id' => $s->cash_register_id,
            'opened_by_user_id' => $s->opened_by_user_id,
            'closed_by_user_id' => $s->closed_by_user_id,
            'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'opening_float' => (string) $s->opening_float,
            'expected_cash' => $s->expected_cash !== null ? (string) $s->expected_cash : null,
            'counted_cash' => $s->counted_cash !== null ? (string) $s->counted_cash : null,
            'difference_amount' => $s->difference_amount !== null ? (string) $s->difference_amount : null,
            'status' => $s->status,
            'notes' => $s->notes,
            'cash_register' => $s->cashRegister ? [
                'id' => $s->cashRegister->id,
                'name' => $s->cashRegister->name,
                'code' => $s->cashRegister->code,
                'branch_id' => $s->cashRegister->branch_id,
            ] : null,
            'opened_by' => $s->openedBy ? ['id' => $s->openedBy->id, 'name' => $s->openedBy->name] : null,
            'closed_by' => $s->closedBy ? ['id' => $s->closedBy->id, 'name' => $s->closedBy->name] : null,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
