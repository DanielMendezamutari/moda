<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreCartItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreCartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $items = StoreCartItem::query()
            ->where('user_id', $user->id)
            ->with(['product' => static function ($q): void {
                $q->select(['id', 'name', 'sku', 'barcode', 'price', 'discount_percent', 'is_active', 'image_path'])
                    ->with(['warehouses:id', 'category:id,title']);
            }])
            ->orderBy('updated_at', 'desc')
            ->get();

        $lines = $items->map(fn (StoreCartItem $row) => $this->serializeLine($row))->values()->all();

        return response()->json([
            'data' => $lines,
            'totals' => $this->computeTotals($lines),
        ]);
    }

    /**
     * Combina el carrito invitado (localStorage) con el del usuario autenticado.
     *
     * @return array<string, mixed>
     */
    public function merge(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $items = $validated['items'] ?? [];

        DB::transaction(function () use ($user, $items): void {
            foreach ($items as $row) {
                $pid = (int) $row['product_id'];
                $qty = (int) $row['quantity'];
                $product = Product::query()->whereKey($pid)->where('is_active', true)->first();
                if ($product === null) {
                    continue;
                }
                $max = $this->maxQtyForProduct($product);
                if ($max <= 0) {
                    continue;
                }
                $qty = min($qty, $max);

                $existing = StoreCartItem::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $pid)
                    ->first();

                if ($existing) {
                    $merged = min($max, (int) $existing->quantity + $qty);
                    $existing->update(['quantity' => $merged]);
                } else {
                    StoreCartItem::query()->create([
                        'user_id' => $user->id,
                        'product_id' => $pid,
                        'quantity' => $qty,
                    ]);
                }
            }
        });

        return $this->index($request);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:999'],
        ]);

        $qty = (int) ($validated['quantity'] ?? 1);
        $product = Product::query()
            ->whereKey((int) $validated['product_id'])
            ->where('is_active', true)
            ->with(['warehouses:id'])
            ->firstOrFail();

        $max = $this->maxQtyForProduct($product);
        if ($max <= 0) {
            throw ValidationException::withMessages([
                'product_id' => ['Este producto no tiene stock disponible.'],
            ]);
        }

        $qty = min($qty, $max);

        $row = StoreCartItem::query()->firstOrNew([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $row->quantity = min($max, (int) $row->quantity + $qty);
        $row->save();
        $row->load(['product' => static function ($q): void {
            $q->select(['id', 'name', 'sku', 'barcode', 'price', 'discount_percent', 'is_active', 'image_path'])
                ->with(['warehouses:id', 'category:id,title']);
        }]);

        return response()->json([
            'data' => $this->serializeLine($row),
            'totals' => $this->computeTotalsFromUser($user->id),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $row = StoreCartItem::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($row === null) {
            abort(404, 'Ítem no encontrado en el carrito.');
        }

        if ((int) $validated['quantity'] === 0) {
            $row->delete();

            return response()->json([
                'data' => null,
                'totals' => $this->computeTotalsFromUser($user->id),
            ]);
        }

        $product->load(['warehouses:id']);
        $max = $this->maxQtyForProduct($product);
        if ($max <= 0) {
            $row->delete();

            return response()->json([
                'data' => null,
                'totals' => $this->computeTotalsFromUser($user->id),
            ], 200);
        }

        $row->quantity = min((int) $validated['quantity'], $max);
        $row->save();
        $row->load(['product' => static function ($q): void {
            $q->select(['id', 'name', 'sku', 'barcode', 'price', 'discount_percent', 'is_active', 'image_path'])
                ->with(['warehouses:id', 'category:id,title']);
        }]);

        return response()->json([
            'data' => $this->serializeLine($row),
            'totals' => $this->computeTotalsFromUser($user->id),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        StoreCartItem::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json([
            'message' => 'Eliminado del carrito.',
            'totals' => $this->computeTotalsFromUser($user->id),
        ]);
    }

    private function maxQtyForProduct(Product $product): int
    {
        $product->loadMissing('warehouses:id');

        return (int) $product->warehouses->sum(static fn ($wh) => (int) $wh->pivot->stock);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, float|int>
     */
    private function computeTotals(array $lines): array
    {
        $qty = 0;
        $sub = 0.0;
        foreach ($lines as $line) {
            $q = (int) ($line['quantity'] ?? 0);
            $qty += $q;
            $sub += (float) ($line['line_total'] ?? 0);
        }

        return [
            'items_count' => $qty,
            'lines_count' => count($lines),
            'subtotal' => round($sub, 2),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function computeTotalsFromUser(int $userId): array
    {
        $items = StoreCartItem::query()
            ->where('user_id', $userId)
            ->with(['product' => static function ($q): void {
                $q->select(['id', 'name', 'sku', 'barcode', 'price', 'discount_percent', 'is_active', 'image_path'])
                    ->with(['warehouses:id', 'category:id,title']);
            }])
            ->get();

        $lines = $items->map(fn (StoreCartItem $row) => $this->serializeLine($row))->values()->all();

        return $this->computeTotals($lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLine(StoreCartItem $row): array
    {
        $p = $row->product;
        if ($p === null) {
            return [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'quantity' => (int) $row->quantity,
                'product' => null,
                'unit_price' => 0.0,
                'line_total' => 0.0,
            ];
        }

        $price = (float) $p->price;
        $d = (float) $p->discount_percent;
        $unit = round($price * (1 - min(max($d, 0), 100) / 100), 2);
        $qty = (int) $row->quantity;

        $imageUrl = $p->image_path ? asset('storage/'.$p->image_path) : null;

        return [
            'id' => $row->id,
            'product_id' => $p->id,
            'quantity' => $qty,
            'unit_price' => $unit,
            'line_total' => round($unit * $qty, 2),
            'product' => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'image_url' => $imageUrl,
                'category' => $p->category ? ['id' => $p->category->id, 'title' => $p->category->title] : null,
            ],
        ];
    }
}
