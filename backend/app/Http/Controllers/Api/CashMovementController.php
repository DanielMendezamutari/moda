<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CashMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashMovement::class);

        $q = CashMovement::query()
            ->with(['session.cashRegister'])
            ->orderByDesc('occurred_at');

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return response()->json(['data' => []]);
            }
            $q->whereHas('session.cashRegister', fn ($w) => $w->where('branch_id', (int) $bid));
        }

        if ($request->filled('cash_register_session_id')) {
            $q->where('cash_register_session_id', (int) $request->input('cash_register_session_id'));
        }
        if ($request->filled('type')) {
            $q->where('type', $request->string('type')->toString());
        }
        if ($request->filled('from')) {
            $q->where('occurred_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $q->where('occurred_at', '<=', $request->date('to')->endOfDay());
        }

        $limit = min((int) $request->input('per_page', 100), 500);

        return response()->json([
            'data' => $q->limit($limit)->get()->map(fn (CashMovement $m) => $this->serializeMovement($m)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CashMovement::class);

        $data = $request->validate([
            'cash_register_session_id' => ['required', 'integer', 'exists:cash_register_sessions,id'],
            'type' => ['required', 'string', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method_payment' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $session = CashRegisterSession::query()->with('cashRegister')->findOrFail((int) $data['cash_register_session_id']);

        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'cash_register_session_id' => ['El turno de caja no está abierto.'],
            ]);
        }

        $policy = new \App\Policies\CashRegisterPolicy;
        if (! $policy->canAccessBranch($request->user(), (int) $session->cashRegister->branch_id)) {
            abort(403, 'No autorizado.');
        }

        $movement = CashMovement::query()->create([
            'cash_register_session_id' => $session->id,
            'type' => $data['type'],
            'source' => 'manual',
            'amount' => round((float) $data['amount'], 2),
            'method_payment' => $data['method_payment'],
            'description' => $data['description'] ?? null,
            'occurred_at' => now(),
            'sale_payment_id' => null,
        ]);

        $movement->load('session.cashRegister');

        return response()->json(['data' => $this->serializeMovement($movement)], 201);
    }

    /**
     * Ticket PDF de un movimiento manual de caja (no venta).
     */
    public function ticket(Request $request, CashMovement $cashMovement): Response
    {
        $this->authorize('view', $cashMovement);

        if ($cashMovement->source === 'sale_payment') {
            abort(422, 'Este movimiento corresponde a una venta: usá el ticket de la venta.');
        }

        $cashMovement->load(['session', 'session.cashRegister.branch']);

        $html = view('exports.cash_movement_ticket_pdf', [
            'm' => $cashMovement,
            'generatedAt' => now()->timezone(config('app.timezone'))->format('d/m/Y H:i:s'),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0.0, 0.0, 226.77, 700.0]);
        $dompdf->render();

        $baseName = 'movimiento-caja-'.$cashMovement->id.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMovement(CashMovement $m): array
    {
        $cr = $m->relationLoaded('session') && $m->session?->cashRegister ? [
            'id' => $m->session->cashRegister->id,
            'name' => $m->session->cashRegister->name,
        ] : null;

        $saleId = null;
        if ($m->sale_payment_id && $m->relationLoaded('salePayment')) {
            $saleId = $m->salePayment?->sale_id;
        }

        $registerName = $m->session?->cashRegister?->name;

        return [
            'id' => $m->id,
            'cash_register_session_id' => $m->cash_register_session_id,
            'type' => $m->type,
            'source' => $m->source,
            'amount' => (string) $m->amount,
            'method_payment' => $m->method_payment,
            'description' => $m->description,
            'occurred_at' => $m->occurred_at?->toIso8601String(),
            'sale_payment_id' => $m->sale_payment_id,
            'sale_id' => $saleId,
            'cash_register' => $cr,
            /** Nombre corto para tablas (evita mostrar JSON en UI). */
            'cash_register_name' => $registerName,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
