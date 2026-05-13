<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversion;
use App\Models\User;
use App\Services\InventoryConversionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ConversionController extends Controller
{
    public function __construct(
        private readonly InventoryConversionService $conversionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversion::class);

        /** @var LengthAwarePaginator<int, Conversion> $paginator */
        $paginator = $this->baseQuery($request)
            ->with([
                'product:id,name,sku',
                'warehouse:id,name,branch_id',
                'unitStart:id,name',
                'unitEnd:id,name',
                'user:id,name',
            ])
            ->latest()
            ->paginate(15)
            ->through(fn (Conversion $c) => $this->serialize($c));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Conversion::class);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'unit_start_id' => ['required', 'integer', 'exists:units,id'],
            'unit_end_id' => ['required', 'integer', 'exists:units,id'],
            'quantity_start' => ['required', 'numeric', 'min:0.0001'],
            'quantity_end' => ['required', 'numeric', 'min:0.0001'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $conversion = $this->conversionService->record($request->user(), $validated);

        return response()->json(['data' => $this->serialize($conversion)], 201);
    }

    public function show(Request $request, Conversion $conversion): JsonResponse
    {
        $this->authorize('view', $conversion);
        $this->assertConversionVisible($request->user(), $conversion);

        $conversion->load([
            'product:id,name,sku',
            'warehouse:id,name,branch_id',
            'unitStart:id,name,dimension',
            'unitEnd:id,name,dimension',
            'user:id,name',
        ]);

        return response()->json(['data' => $this->serialize($conversion)]);
    }

    private function baseQuery(Request $request): Builder
    {
        $q = Conversion::query();

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return $q->whereRaw('1 = 0');
            }
            $q->whereHas('warehouse', static function ($w) use ($bid): void {
                $w->where('branch_id', (int) $bid);
            });
        }

        if ($request->filled('warehouse_id')) {
            $q->where('warehouse_id', (int) $request->query('warehouse_id'));
        }

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(static function ($w) use ($like): void {
                $w->whereHas('product', static function ($p) use ($like): void {
                    $p->where('name', 'like', $like)->orWhere('sku', 'like', $like);
                })->orWhereHas('warehouse', static function ($wh) use ($like): void {
                    $wh->where('name', 'like', $like);
                });
            });
        }

        return $q;
    }

    private function assertConversionVisible(User $user, Conversion $conversion): void
    {
        $conversion->loadMissing('warehouse');
        if ($user->hasRole('admin')) {
            return;
        }
        $bid = $user->branch_id;
        if ($bid === null || ! $conversion->warehouse || (int) $conversion->warehouse->branch_id !== (int) $bid) {
            abort(403, 'No autorizado.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Conversion $c): array
    {
        return [
            'id' => $c->id,
            'product_id' => $c->product_id,
            'warehouse_id' => $c->warehouse_id,
            'unit_start_id' => $c->unit_start_id,
            'unit_end_id' => $c->unit_end_id,
            'user_id' => $c->user_id,
            'quantity_start' => (string) $c->quantity_start,
            'quantity_end' => (string) $c->quantity_end,
            'stock_delta' => (string) $c->stock_delta,
            'description' => $c->description,
            'product' => $c->product ? ['id' => $c->product->id, 'name' => $c->product->name, 'sku' => $c->product->sku] : null,
            'warehouse' => $c->warehouse ? ['id' => $c->warehouse->id, 'name' => $c->warehouse->name] : null,
            'unit_start' => $c->unitStart ? ['id' => $c->unitStart->id, 'name' => $c->unitStart->name] : null,
            'unit_end' => $c->unitEnd ? ['id' => $c->unitEnd->id, 'name' => $c->unitEnd->name] : null,
            'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
