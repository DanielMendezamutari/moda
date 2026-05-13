<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PublicCatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        $rows = Category::query()
            ->where('is_active', true)
            ->whereExists(static function ($q): void {
                $q->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.category_id', 'categories.id')
                    ->where('products.is_active', true);
            })
            ->orderBy('title')
            ->get(['id', 'title']);

        return response()->json(['data' => $rows]);
    }

    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 24);
        $perPage = min(max($perPage, 1), 48);

        $query = $this->publicProductQuery($request);

        /** @var LengthAwarePaginator<int, Product> $paginator */
        $paginator = $query->paginate($perPage)->through(fn (Product $p) => $this->serializePublicProduct($p));

        return response()->json($paginator);
    }

    private function publicProductQuery(Request $request): Builder
    {
        $query = Product::query()
            ->where('is_active', true)
            ->with([
                'category:id,title',
                'warehouses:id',
            ]);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(static function ($q) use ($search): void {
                $like = '%'.$search.'%';
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $categoryId = $request->query('category_id');
        if ($categoryId !== null && $categoryId !== '') {
            $query->where('category_id', (int) $categoryId);
        }

        $query->orderByRaw('category_id IS NULL')
            ->orderBy('category_id')
            ->orderBy('name');

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePublicProduct(Product $product): array
    {
        $imageUrl = null;
        if ($product->image_path) {
            $imageUrl = asset('storage/'.$product->image_path);
        }

        $stockTotal = (int) $product->warehouses->sum(static fn ($wh) => (int) $wh->pivot->stock);

        $plainDesc = $product->description;
        if (is_string($plainDesc)) {
            $plainDesc = trim(preg_replace('/\s+/u', ' ', strip_tags($plainDesc)));
        } else {
            $plainDesc = '';
        }
        $excerpt = $plainDesc !== '' ? mb_substr($plainDesc, 0, 220) : null;

        $price = (float) $product->price;
        $d = (float) $product->discount_percent;
        $final = round($price * (1 - min(max($d, 0), 100) / 100), 2);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'title' => $product->category->title,
            ] : null,
            'price' => round($price, 2),
            'discount_percent' => round($d, 2),
            'final_price' => $final,
            'is_gift_card' => (bool) $product->is_gift_card,
            'description_excerpt' => $excerpt,
            'image_url' => $imageUrl,
            'in_stock' => $stockTotal > 0,
            'stock_total' => $stockTotal,
        ];
    }
}
