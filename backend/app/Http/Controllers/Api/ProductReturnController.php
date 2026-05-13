<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductReturn;
use App\Models\SaleDetail;
use App\Models\User;
use App\Services\ProductReturnInventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductReturnController extends Controller
{
    public function __construct(
        private readonly ProductReturnInventoryService $inventoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductReturn::class);

        /** @var LengthAwarePaginator<int, ProductReturn> $paginator */
        $paginator = $this->baseQuery($request)
            ->with([
                'product:id,name,sku',
                'warehouse:id,name,branch_id',
                'unit:id,name',
                'client:id,full_name,n_document',
                'user:id,name',
                'saleDetail:id,sale_id,quantity',
            ])
            ->latest()
            ->paginate(15)
            ->through(fn (ProductReturn $r) => $this->serializeList($r));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ProductReturn::class);

        $validated = $request->validate([
            'sale_detail_id' => ['required', 'integer', 'exists:sale_details,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'string', Rule::in([
                ProductReturn::TYPE_REPARACION,
                ProductReturn::TYPE_REMPLAZO,
                ProductReturn::TYPE_DEVOLUCION,
            ])],
            'state' => ['required', 'string', Rule::in([
                ProductReturn::STATE_PENDIENTE,
                ProductReturn::STATE_REVISION,
                ProductReturn::STATE_REPARADO,
                ProductReturn::STATE_DESCARTADO,
            ])],
            'description' => ['nullable', 'string', 'max:2000'],
            'resolution_date' => ['nullable', 'date'],
            'description_resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        $detail = SaleDetail::query()
            ->with(['sale:id,client_id,state_sale'])
            ->findOrFail((int) $validated['sale_detail_id']);

        $this->assertSaleDetailVisible($request->user(), $detail);
        $this->assertSaleAllowsReturn($detail);

        $qty = (float) $validated['quantity'];
        $qtyInt = (int) round($qty);
        if ($qtyInt <= 0 || abs($qty - $qtyInt) > 0.0001) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad debe ser un número entero mayor a cero.'],
            ]);
        }

        $soldQty = (int) round((float) $detail->quantity);
        $already = $this->allocatedReturnQuantityForLine((int) $detail->id, null);
        if ($already + $qtyInt > $soldQty) {
            throw ValidationException::withMessages([
                'quantity' => [
                    'No podés registrar más de lo vendido en esta línea (vendido: '.$soldQty.', ya en devoluciones activas: '.$already.').',
                ],
            ]);
        }

        $return = new ProductReturn;
        $return->product_id = (int) $detail->product_id;
        $return->unit_id = $detail->unit_id !== null ? (int) $detail->unit_id : null;
        $return->warehouse_id = (int) $detail->warehouse_id;
        $return->quantity = $qtyInt;
        $return->sale_detail_id = (int) $detail->id;
        $return->client_id = $detail->sale?->client_id !== null ? (int) $detail->sale->client_id : null;
        $return->type = $validated['type'];
        $return->state = $validated['state'];
        $return->description = $validated['description'] ?? null;
        $return->user_id = (int) $request->user()->id;
        $return->resolution_date = isset($validated['resolution_date'])
            ? $request->date('resolution_date')
            : ($return->state === ProductReturn::STATE_REPARADO ? now() : null);
        $return->description_resolution = $validated['description_resolution'] ?? null;
        $return->save();

        if ($return->state === ProductReturn::STATE_REPARADO) {
            $this->inventoryService->syncAfterStateChange($return->fresh(), null);
        }

        $return->load([
            'product:id,name,sku',
            'warehouse:id,name,branch_id',
            'unit:id,name',
            'client:id,full_name,n_document',
            'user:id,name',
            'saleDetail:id,sale_id,quantity',
        ]);

        return response()->json(['data' => $this->serializeDetail($return)], 201);
    }

    public function show(Request $request, ProductReturn $productReturn): JsonResponse
    {
        $this->authorize('view', $productReturn);
        $this->assertReturnVisible($request->user(), $productReturn);

        $productReturn->load([
            'product:id,name,sku',
            'warehouse:id,name,branch_id',
            'unit:id,name',
            'client:id,full_name,n_document',
            'user:id,name',
            'saleDetail:id,sale_id,product_id,warehouse_id,quantity,line_total',
            'saleDetail.sale:id,reference,state_sale',
        ]);

        return response()->json(['data' => $this->serializeDetail($productReturn)]);
    }

    public function update(Request $request, ProductReturn $productReturn): JsonResponse
    {
        $this->authorize('update', $productReturn);
        $this->assertReturnVisible($request->user(), $productReturn);

        $prevState = (string) $productReturn->state;

        $validated = $request->validate([
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'type' => ['sometimes', 'required', 'string', Rule::in([
                ProductReturn::TYPE_REPARACION,
                ProductReturn::TYPE_REMPLAZO,
                ProductReturn::TYPE_DEVOLUCION,
            ])],
            'state' => ['sometimes', 'required', 'string', Rule::in([
                ProductReturn::STATE_PENDIENTE,
                ProductReturn::STATE_REVISION,
                ProductReturn::STATE_REPARADO,
                ProductReturn::STATE_DESCARTADO,
            ])],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution_date' => ['sometimes', 'nullable', 'date'],
            'description_resolution' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('quantity', $validated)) {
            if ($productReturn->inventory_applied_at !== null) {
                throw ValidationException::withMessages([
                    'quantity' => ['No se puede cambiar la cantidad después de ingresar stock (estuvo en reparado). Revertí el estado primero.'],
                ]);
            }
            $qty = (float) $validated['quantity'];
            $qtyInt = (int) round($qty);
            if ($qtyInt <= 0 || abs($qty - $qtyInt) > 0.0001) {
                throw ValidationException::withMessages([
                    'quantity' => ['La cantidad debe ser un número entero mayor a cero.'],
                ]);
            }
            $detail = SaleDetail::query()->findOrFail((int) $productReturn->sale_detail_id);
            $soldQty = (int) round((float) $detail->quantity);
            $already = $this->allocatedReturnQuantityForLine((int) $detail->id, $productReturn->id);
            if ($already + $qtyInt > $soldQty) {
                throw ValidationException::withMessages([
                    'quantity' => [
                        'Excede lo vendido en la línea (vendido: '.$soldQty.', otras devoluciones: '.$already.').',
                    ],
                ]);
            }
            $productReturn->quantity = $qtyInt;
        }

        if (array_key_exists('type', $validated)) {
            $productReturn->type = $validated['type'];
        }
        if (array_key_exists('description', $validated)) {
            $productReturn->description = $validated['description'];
        }
        if (array_key_exists('description_resolution', $validated)) {
            $productReturn->description_resolution = $validated['description_resolution'];
        }

        if (array_key_exists('state', $validated)) {
            $productReturn->state = $validated['state'];
            if ($productReturn->state === ProductReturn::STATE_REPARADO && ! array_key_exists('resolution_date', $validated)) {
                $productReturn->resolution_date = $productReturn->resolution_date ?? now();
            }
        }

        if (array_key_exists('resolution_date', $validated)) {
            $productReturn->resolution_date = $validated['resolution_date'] !== null
                ? $request->date('resolution_date')
                : null;
        }

        $productReturn->save();

        $this->inventoryService->syncAfterStateChange($productReturn->fresh(), $prevState);

        $productReturn->refresh();
        $productReturn->load([
            'product:id,name,sku',
            'warehouse:id,name,branch_id',
            'unit:id,name',
            'client:id,full_name,n_document',
            'user:id,name',
            'saleDetail:id,sale_id,quantity',
        ]);

        return response()->json(['data' => $this->serializeDetail($productReturn)]);
    }

    public function destroy(Request $request, ProductReturn $productReturn): JsonResponse
    {
        $this->authorize('delete', $productReturn);
        $this->assertReturnVisible($request->user(), $productReturn);

        if ($productReturn->inventory_applied_at !== null) {
            throw ValidationException::withMessages([
                'return' => ['No se puede eliminar: ya se ingresó stock. Pasá el registro a otro estado para revertir el inventario, o contactá administración.'],
            ]);
        }

        $productReturn->delete();

        return response()->json(null, 204);
    }

    private function baseQuery(Request $request): Builder
    {
        $q = ProductReturn::query();

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
        if ($request->filled('type')) {
            $q->where('type', $request->string('type')->toString());
        }

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(static function ($w) use ($like): void {
                $w->whereHas('client', static function ($c) use ($like): void {
                    $c->where('full_name', 'like', $like)
                        ->orWhere('n_document', 'like', $like);
                })
                    ->orWhereHas('product', static function ($p) use ($like): void {
                        $p->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like);
                    })
                    ->orWhere('description', 'like', $like);
            });
        }

        return $q;
    }

    private function assertSaleDetailVisible(User $user, SaleDetail $detail): void
    {
        $detail->loadMissing('warehouse');
        if ($user->hasRole('admin')) {
            return;
        }
        $bid = $user->branch_id;
        if ($bid === null || ! $detail->warehouse || (int) $detail->warehouse->branch_id !== (int) $bid) {
            abort(403, 'No autorizado.');
        }
    }

    private function assertReturnVisible(User $user, ProductReturn $productReturn): void
    {
        $productReturn->loadMissing('warehouse');
        if ($user->hasRole('admin')) {
            return;
        }
        $bid = $user->branch_id;
        if ($bid === null || ! $productReturn->warehouse || (int) $productReturn->warehouse->branch_id !== (int) $bid) {
            abort(403, 'No autorizado.');
        }
    }

    private function assertSaleAllowsReturn(SaleDetail $detail): void
    {
        $detail->loadMissing('sale');
        $sale = $detail->sale;
        if ($sale === null) {
            throw ValidationException::withMessages([
                'sale_detail_id' => ['La línea de venta no existe.'],
            ]);
        }
        if ($sale->state_sale === 'cancelled') {
            throw ValidationException::withMessages([
                'sale_detail_id' => ['No se registran devoluciones sobre ventas anuladas.'],
            ]);
        }
    }

    /**
     * Suma cantidades de devoluciones “activas” (no descartadas) para la misma línea.
     */
    private function allocatedReturnQuantityForLine(int $saleDetailId, ?int $excludeReturnId): int
    {
        $q = ProductReturn::query()
            ->where('sale_detail_id', $saleDetailId)
            ->where('state', '!=', ProductReturn::STATE_DESCARTADO);

        if ($excludeReturnId !== null) {
            $q->where('id', '!=', $excludeReturnId);
        }

        return (int) round((float) $q->sum('quantity'));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(ProductReturn $r): array
    {
        return [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'unit_id' => $r->unit_id,
            'warehouse_id' => $r->warehouse_id,
            'quantity' => (string) $r->quantity,
            'sale_detail_id' => $r->sale_detail_id,
            'client_id' => $r->client_id,
            'type' => $r->type,
            'state' => $r->state,
            'description' => $r->description,
            'user_id' => $r->user_id,
            'resolution_date' => $r->resolution_date?->toIso8601String(),
            'description_resolution' => $r->description_resolution,
            'inventory_applied_at' => $r->inventory_applied_at?->toIso8601String(),
            'product' => $r->product ? ['id' => $r->product->id, 'name' => $r->product->name, 'sku' => $r->product->sku] : null,
            'warehouse' => $r->warehouse ? ['id' => $r->warehouse->id, 'name' => $r->warehouse->name] : null,
            'unit' => $r->unit ? ['id' => $r->unit->id, 'name' => $r->unit->name] : null,
            'client' => $r->client ? [
                'id' => $r->client->id,
                'full_name' => $r->client->full_name,
                'n_document' => $r->client->n_document,
            ] : null,
            'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(ProductReturn $r): array
    {
        $base = $this->serializeList($r);
        $saleDetail = $r->saleDetail;
        $base['sale_detail'] = $saleDetail ? [
            'id' => $saleDetail->id,
            'sale_id' => $saleDetail->sale_id,
            'quantity' => (string) $saleDetail->quantity,
            'line_total' => $saleDetail->line_total !== null ? (string) $saleDetail->line_total : null,
            'sale' => $saleDetail->relationLoaded('sale') && $saleDetail->sale ? [
                'id' => $saleDetail->sale->id,
                'reference' => $saleDetail->sale->reference,
                'state_sale' => $saleDetail->sale->state_sale,
            ] : null,
        ] : null;

        return $base;
    }
}
