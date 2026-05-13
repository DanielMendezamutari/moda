<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\ProductImportService;
use App\Support\BarcodeGenerator;
use App\Support\ProductDeletionGuard;
use App\Support\SkuGenerator;
use App\Support\TabularExport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 1), 150);

        /** @var LengthAwarePaginator<int, Product> $paginator */
        $paginator = $this->buildProductListQuery($request)
            ->paginate($perPage)
            ->through(fn (Product $p) => $this->serializeProduct($p));

        return response()->json($paginator);
    }

    /**
     * Exporta datos tabulares (CSV, Excel, Word). Mismos filtros que el índice.
     * format: csv | xlsx | docx
     */
    public function export(Request $request): StreamedResponse|Response
    {
        $this->authorize('viewAny', Product::class);

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'docx'], true)) {
            abort(422, 'Formato no soportado. Usá csv, xlsx o docx.');
        }

        $labels = $this->exportHeaderLabels();
        $rows = $this->buildProductListQuery($request)
            ->get()
            ->map(fn (Product $p) => $this->exportRowOrdered($this->productToExportRow($p)))
            ->all();

        $baseName = 'productos-'.now()->format('Y-m-d-His');

        return match ($format) {
            'csv' => TabularExport::csv($labels, $rows, $baseName),
            'xlsx' => TabularExport::xlsx($labels, $rows, $baseName, 'Productos'),
            'docx' => TabularExport::docx($labels, $rows, $baseName, 'Catálogo de productos'),
        };
    }

    /**
     * Plantilla vacía para importación (columnas fijas, primera fila = encabezados).
     */
    public function importTemplate(Request $request): StreamedResponse|Response
    {
        $this->authorize('create', Product::class);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            abort(422, 'Formato no válido. Usá csv o xlsx.');
        }

        $headers = ProductImportService::templateHeaders();
        $baseName = 'plantilla-importar-productos';

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers): void {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers);
                fclose($out);
            }, $baseName.'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $xlsx = SimpleXLSXGen::fromArray([$headers], 'Importar');

        $categoryRows = [
            ['ID', 'Título (usar en columna «Categoría» de Importar)', 'Activa'],
        ];
        foreach (Category::query()->orderBy('title')->get(['id', 'title', 'is_active']) as $c) {
            $categoryRows[] = [
                $c->id,
                (string) $c->title,
                $c->is_active ? 'Sí' : 'No',
            ];
        }
        $xlsx->addSheet($categoryRows, 'Categorías');

        $warehouseRows = [
            ['ID', 'Nombre', 'Sucursal', 'Estado'],
        ];
        foreach (Warehouse::query()->with('branch:id,name,code')->orderBy('name')->get() as $w) {
            $warehouseRows[] = [
                $w->id,
                (string) $w->name,
                (string) ($w->branch?->name ?? ''),
                (string) $w->state,
            ];
        }
        $xlsx->addSheet($warehouseRows, 'Almacenes');

        return response($xlsx->__toString(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$baseName.'.xlsx"',
        ]);
    }

    /**
     * Importación masiva desde CSV o XLSX (multipart, campo file).
     *
     * @return JsonResponse array{imported: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function importStore(Request $request, ProductImportService $importService): JsonResponse
    {
        $this->authorize('create', Product::class);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ]);

        $upload = $request->file('file');
        $path = $upload->getRealPath();
        if ($path === false || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'file' => ['No se pudo leer el archivo.'],
            ]);
        }

        $ext = strtolower((string) $upload->getClientOriginalExtension());
        $result = $importService->importFromPath($path, $ext);

        return response()->json($result);
    }

    /**
     * Informe PDF. variant: full | list | stock | barcodes
     */
    public function exportReportPdf(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $variant = strtolower((string) $request->query('variant', 'full'));
        if (! in_array($variant, ['full', 'list', 'stock', 'barcodes'], true)) {
            abort(422, 'Variante PDF no válida. Usá full, list, stock o barcodes.');
        }

        $products = $this->buildProductListQuery($request)->get();
        $search = $request->string('search')->trim()->toString();

        $rows = $products->map(fn (Product $p) => $this->pdfReportRow($p))->all();
        $optimal = array_values(array_filter($rows, fn (array $r) => ($r['stock_status'] ?? '') === 'optimal'));
        $attention = array_values(array_filter($rows, fn (array $r) => ($r['stock_status'] ?? '') !== 'optimal'));
        $withBarcode = array_values(array_filter($rows, fn (array $r) => ($r['barcode'] ?? '') !== ''));

        $titles = [
            'full' => 'Informe completo de productos',
            'list' => 'Listado general de productos',
            'stock' => 'Stock óptimo y atención',
            'barcodes' => 'Códigos de barras',
        ];

        $html = view('exports.products_report_pdf', [
            'pdfVariant' => $variant,
            'title' => $titles[$variant],
            'generatedAt' => now()->format('d/m/Y H:i'),
            'searchLabel' => $search !== '' ? $search : null,
            'totalCount' => count($rows),
            'countOptimal' => count($optimal),
            'countAttention' => count($attention),
            'countBarcodes' => count($withBarcode),
            'generalRows' => $rows,
            'stockOptimalRows' => $optimal,
            'stockAttentionRows' => $attention,
            'barcodeRows' => $withBarcode,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        // data:image y recursos embebidos: en Dompdf 3.x conviene tener remoto habilitado para evitar PDF sin gráficos.
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('dpi', 120);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $baseName = 'informe-productos-'.$variant.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$baseName.'.pdf"',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $this->mergeEmptyProductScalars($request, ['cost_price', 'wholesale_price']);

        $validated = $request->validate([
            'generate_sku' => ['sometimes', 'boolean'],
            'sku' => [
                Rule::requiredIf(fn (): bool => ! $request->boolean('generate_sku')),
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'sku'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65000'],
            'price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'umbral' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'warranty_days' => ['sometimes', 'integer', 'min:0', 'max:36500'],
            'is_active' => ['required', 'boolean'],
            'is_gift_card' => ['required', 'boolean'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')],
            'generate_barcode' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        $barcode = $this->normalizeBarcode($validated['barcode'] ?? null);
        // Misma semántica que en update(): leer del request asegura multipart/FormData ("1"/"0").
        if ($barcode === null && $request->boolean('generate_barcode')) {
            $barcode = BarcodeGenerator::randomUniqueEan13();
        }

        $path = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
        }

        $lines = $this->resolveWarehouseLinesForPersist($request, $validated);
        $this->assertDistinctWarehouseIds($lines);
        foreach ($lines as $line) {
            $this->assertWarehouseWhenStock(
                (int) $line['warehouse_id'],
                (int) ($line['stock'] ?? 0),
                (int) ($line['umbral'] ?? 0),
            );
        }

        $sku = $request->boolean('generate_sku')
            ? SkuGenerator::randomUnique()
            : $validated['sku'];

        $product = Product::query()->create([
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'image_path' => $path,
            'price' => $validated['price'],
            'wholesale_price' => $validated['wholesale_price'] ?? null,
            'cost_price' => $validated['cost_price'] ?? null,
            'discount_percent' => $validated['discount_percent'] ?? 0,
            'warranty_days' => $validated['warranty_days'] ?? 0,
            'is_active' => $validated['is_active'],
            'is_gift_card' => $validated['is_gift_card'],
            'category_id' => $validated['category_id'] ?? null,
        ]);

        if ($lines !== []) {
            $product->warehouses()->sync($this->pivotPayloadFromLines($lines));
        }

        $product->load(['category:id,title', 'warehouses:id,name,branch_id', 'warehouses.branch:id,name,code']);

        return response()->json([
            'data' => $this->serializeProduct($product),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $product->load(['category:id,title', 'warehouses:id,name,branch_id', 'warehouses.branch:id,name,code']);

        return response()->json([
            'data' => $this->serializeProduct($product),
        ]);
    }

    /**
     * JSON (PATCH) o multipart (POST) si hay imagen.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $this->mergeEmptyProductScalars($request, ['wholesale_price', 'cost_price']);

        $validated = $request->validate(array_merge([
            'sku' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($product->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'wholesale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'umbral' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'warranty_days' => ['sometimes', 'integer', 'min:0', 'max:36500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_gift_card' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'warehouse_id' => ['sometimes', 'nullable', 'integer', 'exists:warehouses,id'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($product->id)],
            'generate_barcode' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean'],
        ]));

        if (array_key_exists('sku', $validated)) {
            $product->sku = $validated['sku'];
        }

        if (array_key_exists('name', $validated)) {
            $product->name = $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $product->description = $validated['description'];
        }

        if (array_key_exists('price', $validated)) {
            $product->price = $validated['price'];
        }

        if (array_key_exists('wholesale_price', $validated)) {
            $product->wholesale_price = $validated['wholesale_price'];
        }

        if (array_key_exists('cost_price', $validated)) {
            $product->cost_price = $validated['cost_price'];
        }

        if (array_key_exists('discount_percent', $validated)) {
            $product->discount_percent = $validated['discount_percent'];
        }

        if (array_key_exists('warranty_days', $validated)) {
            $product->warranty_days = $validated['warranty_days'];
        }

        if (array_key_exists('is_active', $validated)) {
            $product->is_active = $validated['is_active'];
        }

        if (array_key_exists('is_gift_card', $validated)) {
            $product->is_gift_card = $validated['is_gift_card'];
        }

        if (array_key_exists('category_id', $validated)) {
            $product->category_id = $validated['category_id'];
        }

        if ($request->boolean('generate_barcode')) {
            $product->barcode = BarcodeGenerator::randomUniqueEan13();
        } elseif (array_key_exists('barcode', $validated)) {
            $product->barcode = $this->normalizeBarcode($validated['barcode']);
        }

        if ($request->boolean('remove_image')) {
            $this->deleteStoredImage($product);
            $product->image_path = null;
        }

        if ($request->hasFile('image')) {
            $this->deleteStoredImage($product);
            $product->image_path = $request->file('image')->store('products', 'public');
        }

        $product->save();

        if ($request->boolean('sync_warehouse_lines')) {
            $decoded = json_decode((string) $request->input('warehouse_lines_json', '[]'), true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages([
                    'warehouse_lines_json' => ['Formato inválido.'],
                ]);
            }
            $v = Validator::make(
                ['warehouse_lines' => $decoded],
                $this->warehouseLinesValidationRules(),
            );
            $v->validate();
            $lines = array_values($v->validated()['warehouse_lines']);
            $this->assertDistinctWarehouseIds($lines);
            foreach ($lines as $line) {
                $this->assertWarehouseWhenStock(
                    (int) $line['warehouse_id'],
                    (int) ($line['stock'] ?? 0),
                    (int) ($line['umbral'] ?? 0),
                );
            }
            $product->warehouses()->sync($this->pivotPayloadFromLines($lines));
        } else {
            $pivotTouched = array_key_exists('warehouse_id', $validated)
                || array_key_exists('stock', $validated)
                || array_key_exists('umbral', $validated)
                || array_key_exists('unit_id', $validated);

            if ($pivotTouched) {
                $product->loadMissing('warehouses');
                $current = $product->warehouses->first();
                $warehouseId = array_key_exists('warehouse_id', $validated)
                    ? $validated['warehouse_id']
                    : $current?->id;
                $stock = array_key_exists('stock', $validated)
                    ? (int) $validated['stock']
                    : (int) ($current?->pivot->stock ?? 0);
                $umbral = array_key_exists('umbral', $validated)
                    ? (int) $validated['umbral']
                    : (int) ($current?->pivot->umbral ?? 0);
                $unitId = array_key_exists('unit_id', $validated)
                    ? $validated['unit_id']
                    : ($current?->pivot->unit_id ?? null);

                $this->assertWarehouseWhenStock($warehouseId, $stock, $umbral);

                $product->warehouses()->detach();
                if ($warehouseId !== null) {
                    $product->warehouses()->attach($warehouseId, [
                        'stock' => $stock,
                        'umbral' => $umbral,
                        'unit_id' => $unitId,
                    ]);
                }
            }
        }

        $product->load(['category:id,title', 'warehouses:id,name,branch_id', 'warehouses.branch:id,name,code']);

        return response()->json([
            'data' => $this->serializeProduct($product->fresh()),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        ProductDeletionGuard::assertDeletable($product);

        $this->deleteStoredImage($product);
        $product->delete();

        return response()->json(null, 204);
    }

    private function normalizeBarcode(?string $barcode): ?string
    {
        if ($barcode === null) {
            return null;
        }

        $barcode = trim($barcode);

        return $barcode === '' ? null : $barcode;
    }

    private function assertWarehouseWhenStock(?int $warehouseId, int $stock, int $umbral): void
    {
        if ($stock > 0 || $umbral > 0) {
            if ($warehouseId === null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['Indicá un almacén cuando hay stock o umbral mayor a 0.'],
                ]);
            }
        }
    }

    private function deleteStoredImage(Product $product): void
    {
        if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
            Storage::disk('public')->delete($product->image_path);
        }
    }

    private function buildProductListQuery(Request $request): Builder
    {
        $query = Product::query()
            ->with([
                'category:id,title,is_active',
                'warehouses:id,name,branch_id',
                'warehouses.branch:id,name,code',
            ]);

        $search = $request->string('search')->trim()->toString();
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

        if (! $request->user()->hasRole('admin')) {
            $query->where('is_active', true);
        }

        $query->orderByRaw('category_id IS NULL')
            ->orderBy('category_id')
            ->orderBy('name');

        return $query;
    }

    /**
     * @return list<string>
     */
    private function exportHeaderLabels(): array
    {
        return [
            'SKU',
            'Nombre',
            'Descripción',
            'Categoría',
            'Precio final',
            'Precio mayoreo',
            'Precio costo',
            'Dto. %',
            'Stock total',
            'Stock (1.er almacén)',
            'Umbral (1.er almacén)',
            'Almacenes',
            'Activo',
            'Código barras',
            'Gift card',
            'Garantía (días)',
        ];
    }

    /**
     * @return array<string, string|int|float>
     */
    private function productToExportRow(Product $product): array
    {
        $product->loadMissing([
            'category:id,title,is_active',
            'warehouses:id,name,branch_id',
            'warehouses.branch:id,name,code',
        ]);

        $w = $product->warehouses->first();
        $stockTotal = (int) $product->warehouses->sum(fn ($wh) => (int) $wh->pivot->stock);
        $warehouseNames = $product->warehouses->pluck('name')->filter()->implode(', ');
        $desc = $product->description;
        if (is_string($desc)) {
            $desc = trim(preg_replace('/\s+/u', ' ', $desc));
        } else {
            $desc = '';
        }

        return [
            'sku' => (string) $product->sku,
            'nombre' => (string) $product->name,
            'descripcion' => $desc,
            'categoria' => (string) ($product->category?->title ?? ''),
            'precio_final' => (string) $product->price,
            'precio_mayoreo' => $product->wholesale_price !== null ? (string) $product->wholesale_price : '',
            'precio_costo' => $product->cost_price !== null ? (string) $product->cost_price : '',
            'descuento_pct' => (string) $product->discount_percent,
            'stock_total' => $stockTotal,
            'stock_primer' => $w ? (int) $w->pivot->stock : 0,
            'umbral_primer' => $w ? (int) $w->pivot->umbral : 0,
            'almacenes' => $warehouseNames,
            'activo' => $product->is_active ? 'Sí' : 'No',
            'codigo_barras' => (string) ($product->barcode ?? ''),
            'gift_card' => $product->is_gift_card ? 'Sí' : 'No',
            'garantia_dias' => (string) $product->warranty_days,
        ];
    }

    /**
     * @param  array<string, string|int|float>  $assoc
     * @return list<string>
     */
    private function exportRowOrdered(array $assoc): array
    {
        $keys = [
            'sku', 'nombre', 'descripcion', 'categoria', 'precio_final', 'precio_mayoreo',
            'precio_costo', 'descuento_pct', 'stock_total', 'stock_primer', 'umbral_primer',
            'almacenes', 'activo', 'codigo_barras', 'gift_card', 'garantia_dias',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[] = (string) ($assoc[$k] ?? '');
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function pdfReportRow(Product $product): array
    {
        $product->loadMissing([
            'category:id,title,is_active',
            'warehouses:id,name,branch_id',
            'warehouses.branch:id,name,code',
        ]);

        $stockTotal = (int) $product->warehouses->sum(fn ($wh) => (int) $wh->pivot->stock);
        $warehouseNames = $product->warehouses->pluck('name')->filter()->implode(', ');
        $anyLineLow = $product->warehouses->contains(static function ($w): bool {
            $u = (int) $w->pivot->umbral;
            $s = (int) $w->pivot->stock;

            return $u > 0 && $s <= $u;
        });

        if ($stockTotal === 0) {
            $stockStatus = 'out';
            $stockLabel = 'Sin stock';
        } elseif ($anyLineLow) {
            $stockStatus = 'low';
            $stockLabel = 'Bajo umbral';
        } else {
            $stockStatus = 'optimal';
            $stockLabel = 'Stock óptimo';
        }

        $bc = $product->barcode !== null ? trim((string) $product->barcode) : '';

        return [
            'sku' => (string) $product->sku,
            'nombre' => (string) $product->name,
            'categoria' => (string) ($product->category?->title ?? '—'),
            'precio' => number_format((float) $product->price, 2, ',', '.'),
            'activo' => $product->is_active ? 'Sí' : 'No',
            'stock_total' => $stockTotal,
            'almacenes' => $warehouseNames !== '' ? $warehouseNames : '—',
            'stock_status' => $stockStatus,
            'stock_label' => $stockLabel,
            'barcode' => $bc,
            'barcode_bars_html' => $bc !== '' ? $this->barcodeBarsHtmlForPdf($bc) : '',
        ];
    }

    /**
     * Barras escaneables en PDF: preferir PNG (GD/Imagick) en base64; si falla o no hay raster,
     * SVG incrustado (Dompdf dibuja mal los HTML con position:absolute de Picqer).
     */
    private function barcodeBarsHtmlForPdf(string $barcode): string
    {
        $type = preg_match('/^\d{13}$/', $barcode) === 1
            ? BarcodeGeneratorPNG::TYPE_EAN_13
            : BarcodeGeneratorPNG::TYPE_CODE_128;

        if (extension_loaded('gd') || extension_loaded('imagick')) {
            try {
                $gen = new BarcodeGeneratorPNG;
                // Barras más altas y gruesas para que se vean y escaneen bien al imprimir el PDF.
                $pngBinary = $gen->getBarcode($barcode, $type, 4, 100, [0, 0, 0]);
                $b64 = base64_encode($pngBinary);

                return '<img src="data:image/png;base64,'.$b64.'" alt="" class="barcode-img" />';
            } catch (\Throwable) {
                /* intentar SVG / HTML */
            }
        }

        try {
            $gen = new BarcodeGeneratorSVG;
            $svg = $gen->getBarcode($barcode, $type, 3, 72, '#000000');
            $svg = preg_replace('/^<\?xml[^>]*>\s*/', '', $svg) ?? $svg;
            $svg = preg_replace('/^<!DOCTYPE[^>]*>\s*/', '', $svg) ?? $svg;

            return '<div class="barcode-wrap barcode-svg-embed">'.$svg.'</div>';
        } catch (\Throwable) {
            /* último recurso */
        }

        try {
            $gen = new BarcodeGeneratorHTML;

            return '<div class="barcode-wrap">'.$gen->getBarcode($barcode, $type, 2, 56, '#111827').'</div>';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProduct(Product $product): array
    {
        $product->loadMissing([
            'category:id,title,is_active',
            'warehouses:id,name,branch_id',
            'warehouses.branch:id,name,code',
        ]);

        $imageUrl = null;
        if ($product->image_path) {
            $imageUrl = asset('storage/'.$product->image_path);
        }

        $c = $product->category;
        $w = $product->warehouses->first();

        $warehouseLines = $product->warehouses->map(static function ($wh) {
            return [
                'warehouse_id' => $wh->id,
                'stock' => (int) $wh->pivot->stock,
                'umbral' => (int) $wh->pivot->umbral,
                'sale_price' => $wh->pivot->sale_price !== null ? (string) $wh->pivot->sale_price : null,
                'purchase_price' => $wh->pivot->purchase_price !== null ? (string) $wh->pivot->purchase_price : null,
                'unit_id' => $wh->pivot->unit_id !== null ? (int) $wh->pivot->unit_id : null,
                'warehouse' => [
                    'id' => $wh->id,
                    'name' => $wh->name,
                    'branch' => $wh->branch ? [
                        'id' => $wh->branch->id,
                        'name' => $wh->branch->name,
                        'code' => $wh->branch->code,
                    ] : null,
                ],
            ];
        })->values()->all();

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'description' => $product->description,
            'image_url' => $imageUrl,
            'price' => (string) $product->price,
            'wholesale_price' => $product->wholesale_price !== null ? (string) $product->wholesale_price : null,
            'cost_price' => $product->cost_price !== null ? (string) $product->cost_price : null,
            'discount_percent' => (string) $product->discount_percent,
            'is_gift_card' => (bool) $product->is_gift_card,
            'is_active' => (bool) $product->is_active,
            'warranty_days' => $product->warranty_days,
            'stock' => $w ? (int) $w->pivot->stock : 0,
            'umbral' => $w ? (int) $w->pivot->umbral : 0,
            'category_id' => $product->category_id,
            'warehouse_id' => $w?->id,
            'unit_id' => $w && $w->pivot->unit_id !== null ? (int) $w->pivot->unit_id : null,
            'warehouse_lines' => $warehouseLines,
            'category' => $c ? [
                'id' => $c->id,
                'title' => $c->title,
                'is_active' => (bool) $c->is_active,
            ] : null,
            'warehouse' => $w ? [
                'id' => $w->id,
                'name' => $w->name,
                'branch' => $w->branch ? [
                    'id' => $w->branch->id,
                    'name' => $w->branch->name,
                    'code' => $w->branch->code,
                ] : null,
            ] : null,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function warehouseLinesValidationRules(): array
    {
        return [
            'warehouse_lines' => ['present', 'array'],
            'warehouse_lines.*.warehouse_id' => ['required', 'integer', 'distinct', 'exists:warehouses,id'],
            'warehouse_lines.*.stock' => ['sometimes', 'integer', 'min:0'],
            'warehouse_lines.*.umbral' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'warehouse_lines.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'warehouse_lines.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'warehouse_lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function resolveWarehouseLinesForPersist(Request $request, array $validated): array
    {
        if ($request->has('warehouse_lines_json')) {
            $decoded = json_decode((string) $request->input('warehouse_lines_json', '[]'), true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages([
                    'warehouse_lines_json' => ['Formato inválido.'],
                ]);
            }
            $v = Validator::make(
                ['warehouse_lines' => $decoded],
                $this->warehouseLinesValidationRules(),
            );
            $v->validate();

            return array_values($v->validated()['warehouse_lines']);
        }

        return $this->normalizedWarehouseLines($request, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function normalizedWarehouseLines(Request $request, array $validated): array
    {
        if ($request->has('warehouse_lines')) {
            return array_values($validated['warehouse_lines'] ?? []);
        }
        $wid = $validated['warehouse_id'] ?? null;
        if ($wid !== null) {
            return [[
                'warehouse_id' => (int) $wid,
                'stock' => (int) ($validated['stock'] ?? 0),
                'umbral' => (int) ($validated['umbral'] ?? 0),
                'sale_price' => null,
                'purchase_price' => null,
                'unit_id' => $validated['unit_id'] ?? null,
            ]];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertDistinctWarehouseIds(array $lines): void
    {
        $ids = array_map(static fn (array $l): int => (int) $l['warehouse_id'], $lines);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'warehouse_lines' => ['No podés repetir el mismo almacén en dos filas.'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function pivotPayloadFromLines(array $lines): array
    {
        $sync = [];
        foreach ($lines as $line) {
            $wid = (int) $line['warehouse_id'];
            $sync[$wid] = [
                'stock' => (int) ($line['stock'] ?? 0),
                'umbral' => (int) ($line['umbral'] ?? 0),
                'sale_price' => $this->nullableDecimal($line['sale_price'] ?? null),
                'purchase_price' => $this->nullableDecimal($line['purchase_price'] ?? null),
                'unit_id' => $line['unit_id'] ?? null,
            ];
        }

        return $sync;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     */
    private function mergeEmptyProductScalars(Request $request, array $keys): void
    {
        $merge = [];
        foreach ($keys as $key) {
            if ($request->has($key) && trim((string) ($request->input($key) ?? '')) === '') {
                $merge[$key] = null;
            }
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }
}
