<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transport;
use App\Models\TransportDetail;
use App\Models\User;
use App\Services\TransportInventoryService;
use App\Services\TransportService;
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

class TransportController extends Controller
{
    public function __construct(
        private readonly TransportService $transportService,
        private readonly TransportInventoryService $inventoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transport::class);

        /** @var LengthAwarePaginator<int, Transport> $paginator */
        $paginator = $this->baseQuery($request)
            ->with([
                'warehouseStart:id,name,branch_id',
                'warehouseEnd:id,name,branch_id',
                'user:id,name',
            ])
            ->withCount('details')
            ->latest()
            ->paginate(15)
            ->through(fn (Transport $t) => $this->serializeList($t));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Transport::class);

        $validated = $request->validate([
            'warehouse_start_id' => ['required', 'integer', 'exists:warehouses,id'],
            'warehouse_end_id' => ['required', 'integer', 'exists:warehouses,id'],
            'date_emision' => ['nullable', 'date'],
            'state' => ['nullable', 'string', Rule::in([
                Transport::STATE_SOLICITUD,
                Transport::STATE_REVISION_SALIDA,
                Transport::STATE_SALIDA,
                Transport::STATE_LLEGADA,
                Transport::STATE_REVISION_LLEGADA,
                Transport::STATE_ENTREGA,
            ])],
            'description' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:128'],
            'igv' => ['nullable', 'numeric', 'min:0'],
            'date_entrega' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.price_unit' => ['required', 'numeric', 'min:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
        ]);

        $transport = $this->transportService->createTransport($request->user(), $validated);

        return response()->json(['data' => $this->serializeDetail($transport)], 201);
    }

    public function show(Request $request, Transport $transport): JsonResponse
    {
        $this->authorize('view', $transport);
        $this->assertTransportVisible($request->user(), $transport);

        $transport->load([
            'warehouseStart:id,name,branch_id',
            'warehouseEnd:id,name,branch_id',
            'user:id,name',
            'details.product:id,name,sku',
            'details.unit:id,name',
            'details.userSalida:id,name',
            'details.userEntrega:id,name',
        ]);

        return response()->json(['data' => $this->serializeDetail($transport)]);
    }

    public function update(Request $request, Transport $transport): JsonResponse
    {
        $this->authorize('update', $transport);
        $this->assertTransportVisible($request->user(), $transport);

        $validated = $request->validate([
            'date_emision' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'igv' => ['sometimes', 'numeric', 'min:0'],
            'date_entrega' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('date_emision', $validated)) {
            $transport->date_emision = $validated['date_emision'] !== null
                ? $request->date('date_emision')
                : null;
        }
        if (array_key_exists('description', $validated)) {
            $transport->description = $validated['description'];
        }
        if (array_key_exists('reference', $validated)) {
            $transport->reference = $validated['reference'];
        }
        if (array_key_exists('date_entrega', $validated)) {
            $transport->date_entrega = $validated['date_entrega'] !== null
                ? $request->date('date_entrega')
                : null;
        }

        if (array_key_exists('igv', $validated)) {
            $igv = round((float) $validated['igv'], 2);
            $transport->igv = $igv;
            $importe = round((float) $transport->importe, 2);
            $transport->total = round($importe + $igv, 2);
        }

        $transport->save();

        $transport->load([
            'warehouseStart:id,name,branch_id',
            'warehouseEnd:id,name,branch_id',
            'user:id,name',
            'details.product:id,name,sku',
            'details.unit:id,name',
            'details.userSalida:id,name',
            'details.userEntrega:id,name',
        ]);

        return response()->json(['data' => $this->serializeDetail($transport)]);
    }

    public function destroy(Request $request, Transport $transport): JsonResponse
    {
        $this->authorize('delete', $transport);
        $this->assertTransportVisible($request->user(), $transport);

        $transport->load('details');
        foreach ($transport->details as $d) {
            if ($d->inventory_departed_at !== null || $d->inventory_arrived_at !== null) {
                throw ValidationException::withMessages([
                    'transport' => ['No se puede eliminar: hay líneas con movimiento de inventario (salida o entrega).'],
                ]);
            }
        }

        $transport->delete();

        return response()->json(null, 204);
    }

    public function updateDetail(Request $request, Transport $transport, TransportDetail $transportDetail): JsonResponse
    {
        $this->authorize('update', $transport);
        $this->assertTransportVisible($request->user(), $transport);

        if ((int) $transportDetail->transport_id !== (int) $transport->id) {
            abort(404);
        }

        $prev = (string) $transportDetail->state;

        $validated = $request->validate([
            'state' => ['required', 'string', Rule::in([
                TransportDetail::STATE_SOLICITUD,
                TransportDetail::STATE_SALIDA,
                TransportDetail::STATE_ENTREGA,
            ])],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $next = $validated['state'];
        $this->assertLineStateTransition($prev, $next);

        $transportDetail->state = $next;
        if (array_key_exists('description', $validated)) {
            $transportDetail->description = $validated['description'];
        }

        if ($next === TransportDetail::STATE_SOLICITUD) {
            $transportDetail->user_salida_id = null;
            $transportDetail->date_salida = null;
        } elseif ($next === TransportDetail::STATE_SALIDA) {
            if ($prev === TransportDetail::STATE_SOLICITUD) {
                $transportDetail->user_salida_id = (int) $request->user()->id;
                $transportDetail->date_salida = now();
            }
            if ($prev === TransportDetail::STATE_ENTREGA) {
                $transportDetail->user_entrega_id = null;
                $transportDetail->date_entrega = null;
            }
        } elseif ($next === TransportDetail::STATE_ENTREGA) {
            $transportDetail->user_entrega_id = (int) $request->user()->id;
            $transportDetail->date_entrega = now();
        }

        $transportDetail->save();

        $this->inventoryService->syncAfterLineStateChange($transportDetail->fresh(), $prev, $transport);
        $this->transportService->syncHeaderStateFromLines($transport->fresh('details'));

        $transport->refresh();
        $transport->load([
            'warehouseStart:id,name,branch_id',
            'warehouseEnd:id,name,branch_id',
            'user:id,name',
            'details.product:id,name,sku',
            'details.unit:id,name',
            'details.userSalida:id,name',
            'details.userEntrega:id,name',
        ]);

        return response()->json(['data' => $this->serializeDetail($transport)]);
    }

    public function pdf(Request $request, Transport $transport): Response
    {
        $this->authorize('view', $transport);
        $this->assertTransportVisible($request->user(), $transport);

        $transport->load([
            'warehouseStart:id,name,branch_id',
            'warehouseStart.branch:id,name,code',
            'warehouseEnd:id,name,branch_id',
            'warehouseEnd.branch:id,name,code',
            'user:id,name',
            'details.product:id,name,sku',
            'details.unit:id,name',
        ]);

        $html = view('exports.transport_order_pdf', [
            'transport' => $transport,
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

        $baseName = 'transporte-'.$transport->id.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    public function export(Request $request): StreamedResponse|Response
    {
        $this->authorize('viewAny', Transport::class);

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'docx'], true)) {
            abort(422, 'Formato no soportado. Usá csv, xlsx o docx.');
        }

        $rows = $this->transportListForExport($request)
            ->latest()
            ->limit(5000)
            ->get();

        $labels = $this->transportExportHeaderLabels();
        $dataRows = $rows->map(fn (Transport $t) => $this->transportExportRowOrdered($this->transportToExportRow($t)))->all();

        $baseName = 'transportes-'.now()->format('Y-m-d-His');

        return match ($format) {
            'csv' => TabularExport::csv($labels, $dataRows, $baseName),
            'xlsx' => TabularExport::xlsx($labels, $dataRows, $baseName, 'Transportes'),
            'docx' => TabularExport::docx($labels, $dataRows, $baseName, 'Listado de transportes'),
        };
    }

    public function exportReportPdf(Request $request): Response
    {
        $this->authorize('viewAny', Transport::class);

        $variant = strtolower((string) $request->query('variant', 'list'));
        if (! in_array($variant, ['list', 'summary'], true)) {
            abort(422, 'Variante PDF no válida. Usá list o summary.');
        }

        $transports = $this->transportListForExport($request)
            ->latest()
            ->limit(5000)
            ->get();

        $search = $request->string('search')->trim()->toString();
        $stateFilter = $request->string('state')->trim()->toString();

        $byState = [];
        foreach ([
            Transport::STATE_SOLICITUD,
            Transport::STATE_REVISION_SALIDA,
            Transport::STATE_SALIDA,
            Transport::STATE_LLEGADA,
            Transport::STATE_REVISION_LLEGADA,
            Transport::STATE_ENTREGA,
        ] as $st) {
            $byState[$st] = $transports->where('state', $st)->count();
        }

        $sumTotal = round((float) $transports->sum(fn (Transport $t) => (float) $t->total), 2);

        $titles = [
            'list' => 'Listado de transportes / traslados',
            'summary' => 'Resumen de transportes',
        ];

        $tableRows = $transports->map(fn (Transport $t) => [
            'id' => $t->id,
            'date_emision' => $t->date_emision?->format('d/m/Y'),
            'origin' => $t->warehouseStart?->name,
            'dest' => $t->warehouseEnd?->name,
            'user_name' => $t->user?->name,
            'state' => $t->state,
            'importe' => (string) $t->importe,
            'igv' => (string) $t->igv,
            'total' => (string) $t->total,
            'reference' => $t->reference,
            'created_at' => $t->created_at?->format('d/m/Y H:i'),
        ])->all();

        $html = view('exports.transports_report_pdf', [
            'pdfVariant' => $variant,
            'title' => $titles[$variant],
            'generatedAt' => now()->format('d/m/Y H:i'),
            'searchLabel' => $search !== '' ? $search : null,
            'stateFilterLabel' => $stateFilter !== '' ? $stateFilter : null,
            'countAll' => $transports->count(),
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

        $baseName = 'informe-transportes-'.$variant.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    private function baseQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = Transport::query();

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return $q->whereRaw('1 = 0');
            }
            $q->where(static function ($w) use ($bid): void {
                $w->whereHas('warehouseStart', static function ($wh) use ($bid): void {
                    $wh->where('branch_id', (int) $bid);
                })->orWhereHas('warehouseEnd', static function ($wh) use ($bid): void {
                    $wh->where('branch_id', (int) $bid);
                });
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
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('warehouseStart', static function ($wh) use ($like): void {
                        $wh->where('name', 'like', $like);
                    })
                    ->orWhereHas('warehouseEnd', static function ($wh) use ($like): void {
                        $wh->where('name', 'like', $like);
                    });
            });
        }

        return $q;
    }

    private function assertTransportVisible(User $user, Transport $transport): void
    {
        $transport->loadMissing(['warehouseStart', 'warehouseEnd']);
        if ($user->hasRole('admin')) {
            return;
        }
        $bid = $user->branch_id;
        if ($bid === null) {
            abort(403, 'No autorizado.');

            return;
        }
        $okStart = $transport->warehouseStart && (int) $transport->warehouseStart->branch_id === (int) $bid;
        $okEnd = $transport->warehouseEnd && (int) $transport->warehouseEnd->branch_id === (int) $bid;
        if (! $okStart && ! $okEnd) {
            abort(403, 'No autorizado.');
        }
    }

    private function assertLineStateTransition(string $prev, string $next): void
    {
        $ok = match ($prev) {
            TransportDetail::STATE_SOLICITUD => $next === TransportDetail::STATE_SALIDA,
            TransportDetail::STATE_SALIDA => in_array($next, [TransportDetail::STATE_SOLICITUD, TransportDetail::STATE_ENTREGA], true),
            TransportDetail::STATE_ENTREGA => $next === TransportDetail::STATE_SALIDA,
            default => false,
        };

        if (! $ok) {
            throw ValidationException::withMessages([
                'state' => ['Transición de estado no permitida para la línea.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(Transport $t): array
    {
        return [
            'id' => $t->id,
            'warehouse_start_id' => $t->warehouse_start_id,
            'warehouse_end_id' => $t->warehouse_end_id,
            'user_id' => $t->user_id,
            'date_emision' => $t->date_emision?->toDateString(),
            'state' => $t->state,
            'reference' => $t->reference,
            'importe' => (string) $t->importe,
            'igv' => (string) $t->igv,
            'total' => (string) $t->total,
            'date_entrega' => $t->date_entrega?->toDateString(),
            'items_count' => $t->details_count ?? ($t->relationLoaded('details') ? $t->details->count() : 0),
            'warehouse_start' => $t->warehouseStart ? ['id' => $t->warehouseStart->id, 'name' => $t->warehouseStart->name] : null,
            'warehouse_end' => $t->warehouseEnd ? ['id' => $t->warehouseEnd->id, 'name' => $t->warehouseEnd->name] : null,
            'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(Transport $t): array
    {
        $details = $t->details->map(static function (TransportDetail $item): array {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'unit_id' => $item->unit_id,
                'quantity' => (string) $item->quantity,
                'price_unit' => (string) $item->price_unit,
                'line_total' => $item->line_total !== null ? (string) $item->line_total : null,
                'state' => $item->state,
                'description' => $item->description,
                'user_salida_id' => $item->user_salida_id,
                'date_salida' => $item->date_salida?->toIso8601String(),
                'user_entrega_id' => $item->user_entrega_id,
                'date_entrega' => $item->date_entrega?->toIso8601String(),
                'inventory_departed_at' => $item->inventory_departed_at?->toIso8601String(),
                'inventory_arrived_at' => $item->inventory_arrived_at?->toIso8601String(),
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                ] : null,
                'unit' => $item->unit ? ['id' => $item->unit->id, 'name' => $item->unit->name] : null,
                'user_salida' => $item->userSalida ? ['id' => $item->userSalida->id, 'name' => $item->userSalida->name] : null,
                'user_entrega' => $item->userEntrega ? ['id' => $item->userEntrega->id, 'name' => $item->userEntrega->name] : null,
            ];
        })->values()->all();

        return array_merge($this->serializeList($t), [
            'description' => $t->description,
            'items' => $details,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Transport>
     */
    private function transportListForExport(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $this->baseQuery($request)
            ->with([
                'warehouseStart:id,name,branch_id',
                'warehouseEnd:id,name,branch_id',
                'user:id,name',
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function transportExportHeaderLabels(): array
    {
        return [
            'ID',
            'Fecha emisión',
            'Almacén origen',
            'Almacén destino',
            'Estado',
            'Importe',
            'IGV',
            'Total',
            'Referencia',
            'Usuario',
            'Fecha registro',
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    private function transportToExportRow(Transport $t): array
    {
        return [
            'id' => $t->id,
            'date_emision' => $t->date_emision?->format('Y-m-d'),
            'origin' => $t->warehouseStart?->name,
            'dest' => $t->warehouseEnd?->name,
            'state' => (string) $t->state,
            'importe' => (string) $t->importe,
            'igv' => (string) $t->igv,
            'total' => (string) $t->total,
            'reference' => $t->reference,
            'user_name' => $t->user?->name,
            'created_at' => $t->created_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array<string, string|int|float|null>  $assoc
     * @return list<string>
     */
    private function transportExportRowOrdered(array $assoc): array
    {
        $order = [
            'id', 'date_emision', 'origin', 'dest', 'state', 'importe', 'igv', 'total', 'reference', 'user_name', 'created_at',
        ];
        $out = [];
        foreach ($order as $k) {
            $v = $assoc[$k] ?? '';
            $out[] = $v === null || $v === '' ? '—' : (string) $v;
        }

        return $out;
    }
}
