<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Support\TabularExport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $saleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        /** @var LengthAwarePaginator<int, Sale> $paginator */
        $paginator = $this->saleListQuery($request)
            ->with(['user:id,name', 'client:id,full_name,n_document'])
            ->withCount(['items', 'payments'])
            ->latest()
            ->paginate(15)
            ->through(fn (Sale $s) => $this->serializeSaleList($s));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $validated = $request->validate([
            'reference' => ['nullable', 'string', 'max:64'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'type_client' => ['nullable', Rule::in(['natural', 'juridico'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'igv' => ['required', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.method_payment' => ['required', 'string', 'max:64'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.n_transaction' => ['nullable', 'string', 'max:128'],
            'cash_register_session_id' => ['nullable', 'integer', 'exists:cash_register_sessions,id'],
        ]);

        if (! empty($validated['client_id'])) {
            $this->assertClientInUserScope($request, (int) $validated['client_id']);
        }

        $sale = $this->saleService->createSale($request->user(), $validated);

        return response()->json(['data' => $this->serializeSaleDetail($sale)], 201);
    }

    /**
     * Exportación tabular (mismos filtros que el listado: sucursal + búsqueda).
     * format: csv | xlsx | docx
     */
    public function export(Request $request): StreamedResponse|Response
    {
        $this->authorize('viewAny', Sale::class);

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'docx'], true)) {
            abort(422, 'Formato no soportado. Usá csv, xlsx o docx.');
        }

        $sales = $this->saleListQuery($request)
            ->with(['user:id,name', 'client:id,full_name,n_document'])
            ->latest()
            ->limit(5000)
            ->get();

        $labels = $this->saleExportHeaderLabels();
        $rows = $sales->map(fn (Sale $s) => $this->saleExportRowOrdered($this->saleToExportRow($s)))->all();

        $baseName = 'ventas-'.now()->format('Y-m-d-His');

        return match ($format) {
            'csv' => TabularExport::csv($labels, $rows, $baseName),
            'xlsx' => TabularExport::xlsx($labels, $rows, $baseName, 'Ventas'),
            'docx' => TabularExport::docx($labels, $rows, $baseName, 'Listado de ventas'),
        };
    }

    /**
     * Informe PDF: variant list (tabla) | summary (totales y estados).
     */
    public function exportReportPdf(Request $request): Response
    {
        $this->authorize('viewAny', Sale::class);

        $variant = strtolower((string) $request->query('variant', 'list'));
        if (! in_array($variant, ['list', 'summary'], true)) {
            abort(422, 'Variante PDF no válida. Usá list o summary.');
        }

        $sales = $this->saleListQuery($request)
            ->with(['user:id,name', 'client:id,full_name,n_document'])
            ->latest()
            ->limit(5000)
            ->get();

        $search = $request->string('search')->trim()->toString();

        $validatedRows = $sales->filter(fn (Sale $s) => $s->state_sale === 'validated')->values();
        $cancelledRows = $sales->filter(fn (Sale $s) => $s->state_sale === 'cancelled')->values();

        $sumValidatedTotal = round((float) $validatedRows->sum(fn (Sale $s) => (float) $s->total), 2);

        $titles = [
            'list' => 'Listado de ventas',
            'summary' => 'Resumen de ventas',
        ];

        $tableRows = $sales->map(fn (Sale $s) => [
            'id' => $s->id,
            'reference' => $s->reference,
            'client_name' => $s->client?->full_name,
            'n_document' => $s->client?->n_document,
            'total' => (string) $s->total,
            'state_sale' => $s->state_sale,
            'state_payment' => $s->state_payment,
            'debt' => (string) $s->debt,
            'user_name' => $s->user?->name,
            'created_at' => $s->created_at?->format('d/m/Y H:i'),
        ])->all();

        $html = view('exports.sales_report_pdf', [
            'pdfVariant' => $variant,
            'title' => $titles[$variant],
            'generatedAt' => now()->format('d/m/Y H:i'),
            'searchLabel' => $search !== '' ? $search : null,
            'countAll' => $sales->count(),
            'countValidated' => $validatedRows->count(),
            'countCancelled' => $cancelledRows->count(),
            'sumValidatedTotal' => number_format($sumValidatedTotal, 2, ',', '.'),
            'rows' => $tableRows,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $baseName = 'informe-ventas-'.$variant.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);
        $this->assertSaleVisible($request->user(), $sale);

        $sale->load([
            'user:id,name',
            'client:id,full_name,n_document,branch_id',
            'items.product:id,name,sku',
            'items.warehouse:id,name,branch_id',
            'items.unit:id,name',
            'items.category:id,title',
            'payments',
        ]);

        return response()->json(['data' => $this->serializeSaleDetail($sale)]);
    }

    /**
     * Ticket PDF ancho 80 mm (térmica): vista previa / impresión.
     */
    public function ticket(Request $request, Sale $sale): Response
    {
        $this->authorize('view', $sale);
        $this->assertSaleVisible($request->user(), $sale);

        $sale->load([
            'user:id,name',
            'client:id,full_name,n_document',
            'items.product:id,name,sku',
            'items.unit:id,name',
            'items.warehouse:id,name,branch_id',
            'items.warehouse.branch:id,name,code,address,state',
            'payments',
            'cashRegisterSession.cashRegister:id,name,code,branch_id',
            'cashRegisterSession.cashRegister.branch:id,name,code,address,state',
        ]);

        $branch = $this->resolveTicketBranch($sale);

        $html = view('exports.sale_ticket_pdf', [
            'sale' => $sale,
            'branch' => $branch,
            'ticketConf' => config('ticket'),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        /** @var float[] $size pts: ~80mm ancho, alto según ticket */
        $size = [0.0, 0.0, 226.77, 1200.0];
        $dompdf->setPaper($size);
        $dompdf->render();

        $baseName = 'ticket-venta-'.$sale->id.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    public function update(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('update', $sale);
        $this->assertSaleVisible($request->user(), $sale);

        $data = $request->validate([
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $sale->fill($data);
        $sale->save();

        $sale->load([
            'user:id,name',
            'client:id,full_name,n_document',
            'items.product:id,name,sku',
            'items.warehouse:id,name',
            'payments',
        ]);

        return response()->json(['data' => $this->serializeSaleDetail($sale)]);
    }

    public function destroy(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('delete', $sale);
        $this->assertSaleVisible($request->user(), $sale);

        if ($sale->state_sale === 'cancelled') {
            throw ValidationException::withMessages([
                'sale' => ['La venta ya está anulada.'],
            ]);
        }

        if ($sale->state_sale === 'validated') {
            $this->saleService->cancelValidatedSale($request->user(), $sale);

            return response()->json(null, 204);
        }

        $sale->delete();

        return response()->json(null, 204);
    }

    public function storePayment(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('update', $sale);
        $this->assertSaleVisible($request->user(), $sale);

        $data = $request->validate([
            'method_payment' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'n_transaction' => ['nullable', 'string', 'max:128'],
        ]);

        $sale = $this->saleService->addPayment($request->user(), $sale, $data);

        return response()->json(['data' => $this->serializeSaleDetail($sale)], 201);
    }

    private function saleListQuery(Request $request): Builder
    {
        $q = Sale::query();

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return $q->whereRaw('1 = 0');
            }
            $q->whereHas('items.warehouse', static function ($w) use ($bid): void {
                $w->where('branch_id', (int) $bid);
            });
        }

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(static function ($w) use ($like): void {
                $w->where('reference', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('client', static function ($cq) use ($like): void {
                        $cq->where('full_name', 'like', $like)
                            ->orWhere('n_document', 'like', $like);
                    });
            });
        }

        return $q;
    }

    private function assertClientInUserScope(Request $request, int $clientId): void
    {
        $client = Client::query()->findOrFail($clientId);
        if ($request->user()->hasRole('admin')) {
            return;
        }
        $bid = $request->user()->branch_id;
        if ($bid === null || (int) $client->branch_id !== (int) $bid) {
            abort(403, 'No autorizado.');
        }
    }

    /**
     * Sucursal para dirección en ticket: turno de caja o primer almacén de las líneas.
     */
    private function resolveTicketBranch(Sale $sale): ?Branch
    {
        $sale->loadMissing([
            'cashRegisterSession.cashRegister.branch:id,name,code,address,state',
            'items.warehouse.branch:id,name,code,address,state',
        ]);

        $fromRegister = $sale->cashRegisterSession?->cashRegister?->branch;
        if ($fromRegister instanceof Branch) {
            return $fromRegister;
        }

        foreach ($sale->items as $item) {
            $b = $item->warehouse?->branch;
            if ($b instanceof Branch) {
                return $b;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function saleExportHeaderLabels(): array
    {
        return [
            'ID',
            'Referencia',
            'Cliente',
            'Nº documento',
            'Subtotal',
            'IGV',
            'Total',
            'Estado venta',
            'Estado pago',
            'Deuda',
            'Pagado',
            'Usuario',
            'Fecha registro',
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    private function saleToExportRow(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'client_name' => $sale->client?->full_name,
            'n_document' => $sale->client?->n_document,
            'subtotal' => (string) $sale->subtotal,
            'igv' => (string) $sale->igv,
            'total' => (string) $sale->total,
            'state_sale' => (string) $sale->state_sale,
            'state_payment' => (string) $sale->state_payment,
            'debt' => (string) $sale->debt,
            'paid_out' => (string) $sale->paid_out,
            'user_name' => $sale->user?->name,
            'created_at' => $sale->created_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array<string, string|int|float|null>  $assoc
     * @return list<string>
     */
    private function saleExportRowOrdered(array $assoc): array
    {
        $order = [
            'id', 'reference', 'client_name', 'n_document', 'subtotal', 'igv', 'total',
            'state_sale', 'state_payment', 'debt', 'paid_out', 'user_name', 'created_at',
        ];
        $out = [];
        foreach ($order as $k) {
            $v = $assoc[$k] ?? '';
            $out[] = $v === null || $v === '' ? '—' : (string) $v;
        }

        return $out;
    }

    private function assertSaleVisible(User $user, Sale $sale): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        $bid = $user->branch_id;
        if ($bid === null) {
            abort(403, 'No autorizado.');
        }
        $sale->loadMissing('items.warehouse');
        foreach ($sale->items as $item) {
            $wh = $item->warehouse;
            if ($wh && (int) $wh->branch_id === (int) $bid) {
                return;
            }
        }
        abort(403, 'No autorizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSaleList(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'user_id' => $sale->user_id,
            'client_id' => $sale->client_id,
            'subtotal' => (string) $sale->subtotal,
            'igv' => (string) $sale->igv,
            'total' => (string) $sale->total,
            'state_sale' => $sale->state_sale,
            'state_payment' => $sale->state_payment,
            'debt' => (string) $sale->debt,
            'paid_out' => (string) $sale->paid_out,
            'cash_register_session_id' => $sale->cash_register_session_id,
            'date_validation' => $sale->date_validation?->toIso8601String(),
            'date_pay_complete' => $sale->date_pay_complete?->toIso8601String(),
            'items_count' => $sale->items_count,
            'payments_count' => $sale->payments_count,
            'user' => $sale->user ? ['id' => $sale->user->id, 'name' => $sale->user->name] : null,
            'client' => $sale->client ? [
                'id' => $sale->client->id,
                'full_name' => $sale->client->full_name,
                'n_document' => $sale->client->n_document,
            ] : null,
            'created_at' => $sale->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSaleDetail(Sale $sale): array
    {
        $items = $sale->items->map(static function ($item): array {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'unit_id' => $item->unit_id,
                'category_id' => $item->category_id,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'discount' => (string) $item->discount,
                'line_subtotal' => $item->line_subtotal !== null ? (string) $item->line_subtotal : null,
                'line_total' => $item->line_total !== null ? (string) $item->line_total : null,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                ] : null,
                'warehouse' => $item->warehouse ? [
                    'id' => $item->warehouse->id,
                    'name' => $item->warehouse->name,
                ] : null,
                'unit' => $item->relationLoaded('unit') && $item->unit ? [
                    'id' => $item->unit->id,
                    'name' => $item->unit->name,
                ] : null,
                'category' => $item->relationLoaded('category') && $item->category ? [
                    'id' => $item->category->id,
                    'title' => $item->category->title,
                ] : null,
            ];
        })->values()->all();

        $payments = $sale->payments->map(static function ($p): array {
            return [
                'id' => $p->id,
                'method_payment' => $p->method_payment,
                'amount' => (string) $p->amount,
                'n_transaction' => $p->n_transaction,
                'created_at' => $p->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'user_id' => $sale->user_id,
            'client_id' => $sale->client_id,
            'type_client' => $sale->type_client,
            'description' => $sale->description,
            'subtotal' => (string) $sale->subtotal,
            'igv' => (string) $sale->igv,
            'total' => (string) $sale->total,
            'state_sale' => $sale->state_sale,
            'state_payment' => $sale->state_payment,
            'debt' => (string) $sale->debt,
            'paid_out' => (string) $sale->paid_out,
            'date_validation' => $sale->date_validation?->toIso8601String(),
            'date_pay_complete' => $sale->date_pay_complete?->toIso8601String(),
            'user' => $sale->user ? ['id' => $sale->user->id, 'name' => $sale->user->name] : null,
            'client' => $sale->client ? [
                'id' => $sale->client->id,
                'full_name' => $sale->client->full_name,
                'n_document' => $sale->client->n_document,
            ] : null,
            'items' => $items,
            /** Alias según modelo Venta–Cotización (`sale_details`). */
            'sale_details' => $items,
            'payments' => $payments,
            'cash_register_session_id' => $sale->cash_register_session_id,
            'created_at' => $sale->created_at?->toIso8601String(),
            'updated_at' => $sale->updated_at?->toIso8601String(),
        ];
    }
}
