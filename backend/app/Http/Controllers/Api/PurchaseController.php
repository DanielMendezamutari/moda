<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\PurchaseInventoryService;
use App\Services\PurchaseService;
use App\Support\TabularExport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
        private readonly PurchaseInventoryService $inventoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Purchase::class);

        /** @var LengthAwarePaginator<int, Purchase> $paginator */
        $paginator = $this->baseQuery($request)
            ->with([
                'warehouse:id,name,branch_id',
                'user:id,name',
                'supplier:id,name,ruc',
            ])
            ->withCount('items')
            ->latest()
            ->paginate(15)
            ->through(fn (Purchase $p) => $this->serializeList($p));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Purchase::class);

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'date_emision' => ['nullable', 'date'],
            'state' => ['nullable', 'string', Rule::in([
                Purchase::STATE_SOLICITUD,
                Purchase::STATE_REVISION,
                Purchase::STATE_PARCIAL,
                Purchase::STATE_ENTREGADO,
            ])],
            'type_comprobant' => ['nullable', 'string', 'max:64'],
            'n_comprobant' => ['nullable', 'string', 'max:128'],
            'reference' => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'requester_id' => ['required', 'integer', 'exists:users,id'],
            'igv' => ['nullable', 'numeric', 'min:0'],
            'date_entrega' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.price_unit' => ['required', 'numeric', 'min:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.update_sale_price' => ['sometimes', 'boolean'],
            'items.*.new_sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $purchase = $this->purchaseService->createPurchase($request->user(), $validated);

        return response()->json(['data' => $this->serializeDetail($purchase)], 201);
    }

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);
        $this->assertPurchaseVisible($request->user(), $purchase);

        $purchase->load([
            'warehouse:id,name,branch_id',
            'user:id,name',
            'supplier:id,name,ruc,email,phone',
            'items.product:id,name,sku',
            'items.unit:id,name',
            'items.userEntrega:id,name',
        ]);

        return response()->json(['data' => $this->serializeDetail($purchase)]);
    }

    public function update(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('update', $purchase);
        $this->assertPurchaseVisible($request->user(), $purchase);

        $validated = $request->validate([
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'date_emision' => ['sometimes', 'nullable', 'date'],
            'state' => ['sometimes', 'string', Rule::in([
                Purchase::STATE_SOLICITUD,
                Purchase::STATE_REVISION,
                Purchase::STATE_PARCIAL,
                Purchase::STATE_ENTREGADO,
            ])],
            'type_comprobant' => ['sometimes', 'nullable', 'string', 'max:64'],
            'n_comprobant' => ['sometimes', 'nullable', 'string', 'max:128'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'requester_id' => ['sometimes', 'integer', 'exists:users,id'],
            'igv' => ['sometimes', 'numeric', 'min:0'],
            'date_entrega' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('supplier_id', $validated)) {
            $purchase->supplier_id = $validated['supplier_id'];
        }
        if (array_key_exists('date_emision', $validated)) {
            $purchase->date_emision = $validated['date_emision'] !== null
                ? $request->date('date_emision')
                : null;
        }
        if (array_key_exists('state', $validated)) {
            $purchase->state = $validated['state'];
        }
        if (array_key_exists('type_comprobant', $validated)) {
            $purchase->type_comprobant = $validated['type_comprobant'];
        }
        if (array_key_exists('n_comprobant', $validated)) {
            $purchase->n_comprobant = $validated['n_comprobant'];
        }
        if (array_key_exists('reference', $validated)) {
            $purchase->reference = $validated['reference'];
        }
        if (array_key_exists('description', $validated)) {
            $purchase->description = $validated['description'];
        }
        if (array_key_exists('notes', $validated)) {
            $purchase->notes = $validated['notes'];
        }
        if (array_key_exists('requester_id', $validated)) {
            $purchase->user_id = (int) $validated['requester_id'];
        }
        if (array_key_exists('date_entrega', $validated)) {
            $purchase->date_entrega = $validated['date_entrega'] !== null
                ? $request->date('date_entrega')
                : null;
        }

        if (array_key_exists('igv', $validated)) {
            $igv = round((float) $validated['igv'], 2);
            $purchase->igv = $igv;
            $importe = round((float) $purchase->importe, 2);
            $purchase->total = round($importe + $igv, 2);
        }

        $purchase->save();

        $purchase->load([
            'warehouse:id,name,branch_id',
            'user:id,name',
            'supplier:id,name,ruc',
            'items.product:id,name,sku',
            'items.unit:id,name',
            'items.userEntrega:id,name',
        ]);

        return response()->json(['data' => $this->serializeDetail($purchase)]);
    }

    public function destroy(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('delete', $purchase);
        $this->assertPurchaseVisible($request->user(), $purchase);

        $purchase->load('items');
        foreach ($purchase->items as $item) {
            if ($item->inventory_received_at !== null) {
                throw ValidationException::withMessages([
                    'purchase' => ['No se puede eliminar: hay líneas ya recibidas en inventario.'],
                ]);
            }
            if ($item->state === PurchaseItem::STATE_ENTREGADO) {
                throw ValidationException::withMessages([
                    'purchase' => ['No se puede eliminar: hay líneas marcadas como entregadas.'],
                ]);
            }
        }

        $purchase->delete();

        return response()->json(null, 204);
    }

    /**
     * Actualiza estado de línea (p. ej. entregado → ingresa stock).
     */
    public function updateItem(Request $request, Purchase $purchase, PurchaseItem $purchaseItem): JsonResponse
    {
        $this->authorize('update', $purchase);
        $this->assertPurchaseVisible($request->user(), $purchase);

        if ((int) $purchaseItem->purchase_id !== (int) $purchase->id) {
            abort(404);
        }

        $prev = (string) $purchaseItem->state;

        $validated = $request->validate([
            'state' => ['required', 'string', Rule::in([
                PurchaseItem::STATE_SOLICITUD,
                PurchaseItem::STATE_ENTREGADO,
            ])],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'date_entrega' => ['sometimes', 'nullable', 'date'],
        ]);

        $purchaseItem->state = $validated['state'];
        if (array_key_exists('description', $validated)) {
            $purchaseItem->description = $validated['description'];
        }

        if ($purchaseItem->state === PurchaseItem::STATE_ENTREGADO) {
            $purchaseItem->user_entrega_id = (int) $request->user()->id;
            $purchaseItem->date_entrega = isset($validated['date_entrega']) && $validated['date_entrega'] !== null
                ? $request->date('date_entrega')
                : now();
        } else {
            $purchaseItem->user_entrega_id = null;
            $purchaseItem->date_entrega = null;
        }

        $purchaseItem->save();

        $this->inventoryService->syncAfterLineStateChange($purchaseItem->fresh(), $prev, $purchase);
        $this->purchaseService->syncHeaderStateFromLines($purchase->fresh('items'));

        $purchase->refresh();
        $purchase->load([
            'warehouse:id,name,branch_id',
            'user:id,name',
            'supplier:id,name,ruc',
            'items.product:id,name,sku',
            'items.unit:id,name',
            'items.userEntrega:id,name',
        ]);

        return response()->json(['data' => $this->serializeDetail($purchase)]);
    }

    private function baseQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = Purchase::query();

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return $q->whereRaw('1 = 0');
            }
            $q->whereHas('warehouse', static function ($w) use ($bid): void {
                $w->where('branch_id', (int) $bid);
            });
        }

        if ($request->filled('state')) {
            $q->where('state', $request->string('state')->toString());
        }

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(static function ($w) use ($like): void {
                $w->where('reference', 'like', $like)
                    ->orWhere('n_comprobant', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('supplier', static function ($s) use ($like): void {
                        $s->where('name', 'like', $like)
                            ->orWhere('ruc', 'like', $like);
                    });
            });
        }

        return $q;
    }

    private function assertPurchaseVisible(User $user, Purchase $purchase): void
    {
        $purchase->loadMissing('warehouse');
        if ($user->hasRole('admin')) {
            return;
        }
        $bid = $user->branch_id;
        if ($bid === null || ! $purchase->warehouse || (int) $purchase->warehouse->branch_id !== (int) $bid) {
            abort(403, 'No autorizado.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'warehouse_id' => $p->warehouse_id,
            'user_id' => $p->user_id,
            'supplier_id' => $p->supplier_id,
            'date_emision' => $p->date_emision?->toDateString(),
            'state' => $p->state,
            'type_comprobant' => $p->type_comprobant,
            'n_comprobant' => $p->n_comprobant,
            'reference' => $p->reference,
            'importe' => (string) $p->importe,
            'igv' => (string) $p->igv,
            'total' => (string) $p->total,
            'date_entrega' => $p->date_entrega?->toDateString(),
            'items_count' => $p->items_count ?? ($p->relationLoaded('items') ? $p->items->count() : 0),
            'warehouse' => $p->warehouse ? ['id' => $p->warehouse->id, 'name' => $p->warehouse->name] : null,
            'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
            'solicitante' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
            'notes' => $p->notes,
            'supplier' => $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->name] : null,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(Purchase $p): array
    {
        $items = $p->items->map(static function (PurchaseItem $item): array {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'unit_id' => $item->unit_id,
                'quantity' => (string) $item->quantity,
                'price_unit' => (string) $item->unit_cost,
                'line_total' => $item->line_total !== null ? (string) $item->line_total : null,
                'state' => $item->state,
                'description' => $item->description,
                'user_entrega_id' => $item->user_entrega_id,
                'date_entrega' => $item->date_entrega?->toIso8601String(),
                'inventory_received_at' => $item->inventory_received_at?->toIso8601String(),
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                ] : null,
                'unit' => $item->unit ? ['id' => $item->unit->id, 'name' => $item->unit->name] : null,
                'user_entrega' => $item->userEntrega ? ['id' => $item->userEntrega->id, 'name' => $item->userEntrega->name] : null,
            ];
        })->values()->all();

        return array_merge($this->serializeList($p), [
            'description' => $p->description,
            'items' => $items,
        ]);
    }

    /**
     * Documento PDF de una orden de compra (A4).
     */
    public function pdf(Request $request, Purchase $purchase): Response
    {
        $this->authorize('view', $purchase);
        $this->assertPurchaseVisible($request->user(), $purchase);

        $purchase->load([
            'warehouse:id,name,branch_id',
            'warehouse.branch:id,name,code',
            'user:id,name',
            'supplier:id,name,ruc,email,phone',
            'items.product:id,name,sku',
            'items.unit:id,name',
        ]);

        $html = view('exports.purchase_order_pdf', [
            'purchase' => $purchase,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $baseName = 'orden-compra-'.$purchase->id.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    /**
     * Exportación tabular (mismos filtros que el listado: búsqueda, estado).
     * format: csv | xlsx | docx
     */
    public function export(Request $request): StreamedResponse|Response
    {
        $this->authorize('viewAny', Purchase::class);

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'docx'], true)) {
            abort(422, 'Formato no soportado. Usá csv, xlsx o docx.');
        }

        $purchases = $this->purchaseListForExport($request)
            ->latest()
            ->limit(5000)
            ->get();

        $labels = $this->purchaseExportHeaderLabels();
        $rows = $purchases->map(fn (Purchase $p) => $this->purchaseExportRowOrdered($this->purchaseToExportRow($p)))->all();

        $baseName = 'compras-'.now()->format('Y-m-d-His');

        return match ($format) {
            'csv' => TabularExport::csv($labels, $rows, $baseName),
            'xlsx' => TabularExport::xlsx($labels, $rows, $baseName, 'Compras'),
            'docx' => TabularExport::docx($labels, $rows, $baseName, 'Listado de compras'),
        };
    }

    /**
     * Informe PDF: variant list (tabla) | summary (totales y estados).
     */
    public function exportReportPdf(Request $request): Response
    {
        $this->authorize('viewAny', Purchase::class);

        $variant = strtolower((string) $request->query('variant', 'list'));
        if (! in_array($variant, ['list', 'summary'], true)) {
            abort(422, 'Variante PDF no válida. Usá list o summary.');
        }

        $purchases = $this->purchaseListForExport($request)
            ->latest()
            ->limit(5000)
            ->get();

        $search = $request->string('search')->trim()->toString();
        $stateFilter = $request->string('state')->trim()->toString();

        $byState = [
            Purchase::STATE_SOLICITUD => $purchases->where('state', Purchase::STATE_SOLICITUD)->count(),
            Purchase::STATE_REVISION => $purchases->where('state', Purchase::STATE_REVISION)->count(),
            Purchase::STATE_PARCIAL => $purchases->where('state', Purchase::STATE_PARCIAL)->count(),
            Purchase::STATE_ENTREGADO => $purchases->where('state', Purchase::STATE_ENTREGADO)->count(),
        ];

        $sumTotal = round((float) $purchases->sum(fn (Purchase $p) => (float) $p->total), 2);

        $titles = [
            'list' => 'Listado de compras',
            'summary' => 'Resumen de compras',
        ];

        $tableRows = $purchases->map(fn (Purchase $p) => [
            'id' => $p->id,
            'date_emision' => $p->date_emision?->format('d/m/Y'),
            'supplier_name' => $p->supplier?->name,
            'warehouse_name' => $p->warehouse?->name,
            'user_name' => $p->user?->name,
            'state' => $p->state,
            'importe' => (string) $p->importe,
            'igv' => (string) $p->igv,
            'total' => (string) $p->total,
            'n_comprobant' => $p->n_comprobant,
            'reference' => $p->reference,
            'created_at' => $p->created_at?->format('d/m/Y H:i'),
        ])->all();

        $html = view('exports.purchases_report_pdf', [
            'pdfVariant' => $variant,
            'title' => $titles[$variant],
            'generatedAt' => now()->format('d/m/Y H:i'),
            'searchLabel' => $search !== '' ? $search : null,
            'stateFilterLabel' => $stateFilter !== '' ? $stateFilter : null,
            'countAll' => $purchases->count(),
            'byState' => $byState,
            'sumTotal' => number_format($sumTotal, 2, ',', '.'),
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

        $baseName = 'informe-compras-'.$variant.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Purchase>
     */
    private function purchaseListForExport(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $this->baseQuery($request)
            ->with([
                'warehouse:id,name,branch_id',
                'user:id,name',
                'supplier:id,name,ruc',
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function purchaseExportHeaderLabels(): array
    {
        return [
            'ID',
            'Fecha emisión',
            'Proveedor',
            'RUC',
            'Almacén',
            'Solicitante',
            'Estado',
            'Importe',
            'IGV',
            'Total',
            'Nº comprobante',
            'Referencia',
            'Fecha registro',
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    private function purchaseToExportRow(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'date_emision' => $p->date_emision?->format('Y-m-d'),
            'supplier_name' => $p->supplier?->name,
            'supplier_ruc' => $p->supplier?->ruc,
            'warehouse_name' => $p->warehouse?->name,
            'user_name' => $p->user?->name,
            'state' => (string) $p->state,
            'importe' => (string) $p->importe,
            'igv' => (string) $p->igv,
            'total' => (string) $p->total,
            'n_comprobant' => $p->n_comprobant,
            'reference' => $p->reference,
            'created_at' => $p->created_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array<string, string|int|float|null>  $assoc
     * @return list<string>
     */
    private function purchaseExportRowOrdered(array $assoc): array
    {
        $order = [
            'id', 'date_emision', 'supplier_name', 'supplier_ruc', 'warehouse_name', 'user_name', 'state',
            'importe', 'igv', 'total', 'n_comprobant', 'reference', 'created_at',
        ];
        $out = [];
        foreach ($order as $k) {
            $v = $assoc[$k] ?? '';
            $out[] = $v === null || $v === '' ? '—' : (string) $v;
        }

        return $out;
    }
}
