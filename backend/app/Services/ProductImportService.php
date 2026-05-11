<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\BarcodeGenerator;
use App\Support\SkuGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

final class ProductImportService
{
    public const MAX_ROWS = 500;

    /**
     * Primera fila del archivo de plantilla / importación (orden fijo).
     *
     * @return list<string>
     */
    public static function templateHeaders(): array
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
            'ID almacén',
            'Stock',
            'Umbral',
            'Activo',
            'Código barras',
            'Generar código barras',
            'Gift card',
            'Garantía (días)',
        ];
    }

    /**
     * @return array{imported: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function importFromPath(string $absolutePath, string $extension): array
    {
        $extension = strtolower(ltrim($extension, '.'));
        $rows = match ($extension) {
            'csv', 'txt' => $this->readCsvRows($absolutePath),
            'xlsx' => $this->readXlsxRows($absolutePath),
            default => throw ValidationException::withMessages([
                'file' => ['Formato no soportado. Usá CSV o XLSX.'],
            ]),
        };

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['El archivo no tiene filas de datos.'],
            ]);
        }

        $header = array_shift($rows);
        if (! is_array($header)) {
            throw ValidationException::withMessages([
                'file' => ['Encabezados inválidos.'],
            ]);
        }
        $this->stripBomFromFirstHeaderCell($header);

        $colMap = $this->buildColumnMap($header);
        $this->assertRequiredColumns($colMap);

        $dataRows = array_values(array_filter($rows, fn (array $r) => ! $this->isRowBlank($r)));
        if (count($dataRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => ['Máximo '.self::MAX_ROWS.' productos por archivo.'],
            ]);
        }

        $imported = 0;
        $errors = [];
        $seenSku = [];
        $seenBarcode = [];

        $rowNum = 2;
        foreach ($dataRows as $line) {
            $assoc = $this->rowToAssoc($line, $colMap);
            try {
                $name = $this->stringCell($assoc['nombre'] ?? null);
                if ($name === '') {
                    $rowNum++;
                    continue;
                }

                $skuRaw = $this->stringCell($assoc['sku'] ?? null);
                $sku = $skuRaw !== '' ? $skuRaw : SkuGenerator::randomUnique();
                $skuKey = mb_strtolower($sku);
                if (isset($seenSku[$skuKey])) {
                    throw new \RuntimeException('El SKU «'.$sku.'» está repetido en el archivo.');
                }
                if (Product::query()->where('sku', $sku)->exists()) {
                    throw new \RuntimeException('Ya existe un producto con SKU «'.$sku.'».');
                }
                $seenSku[$skuKey] = true;

                $price = $this->parseDecimal($assoc['precio_final'] ?? null, 'Precio final');
                $wholesale = $this->parseOptionalDecimal($assoc['precio_mayoreo'] ?? null);
                $cost = $this->parseOptionalDecimal($assoc['precio_costo'] ?? null);
                $discount = $this->parseOptionalDecimal($assoc['dto_pct'] ?? null) ?? '0';
                $discountNum = (float) $discount;
                if ($discountNum < 0 || $discountNum > 100) {
                    throw new \RuntimeException('Dto. % debe estar entre 0 y 100.');
                }

                $warranty = $this->parseInt($assoc['garantia_dias'] ?? null, 0);

                $isActive = $this->parseBool($assoc['activo'] ?? null, true);
                $isGift = $this->parseBool($assoc['gift_card'] ?? null, false);

                $categoryId = $this->resolveCategoryId($assoc['categoria'] ?? null);

                $warehouseId = $this->parseOptionalInt($assoc['warehouse_id'] ?? null);
                $stock = $this->parseInt($assoc['stock'] ?? null, 0);
                $umbral = $this->parseInt($assoc['umbral'] ?? null, 0);

                if (($stock > 0 || $umbral > 0) && $warehouseId === null) {
                    throw new \RuntimeException('Indicá ID almacén cuando Stock o Umbral son mayores a 0.');
                }
                if ($warehouseId !== null && ! Warehouse::query()->whereKey($warehouseId)->exists()) {
                    throw new \RuntimeException('ID almacén '.$warehouseId.' no existe.');
                }

                $genBarcode = $this->parseBool($assoc['generar_barcode'] ?? null, false);
                $barcodeRaw = $this->stringCell($assoc['barcode'] ?? null);
                $barcode = null;
                if ($genBarcode) {
                    $barcode = BarcodeGenerator::randomUniqueEan13();
                } elseif ($barcodeRaw !== '') {
                    $barcode = $barcodeRaw;
                    if (Product::query()->where('barcode', $barcode)->exists()) {
                        throw new \RuntimeException('El código de barras ya está en uso.');
                    }
                    $bk = mb_strtolower($barcode);
                    if (isset($seenBarcode[$bk])) {
                        throw new \RuntimeException('Código de barras repetido en el archivo.');
                    }
                    $seenBarcode[$bk] = true;
                }

                $description = $this->stringCell($assoc['descripcion'] ?? null);
                $description = $description !== '' ? $description : null;

                DB::transaction(function () use ($sku, $barcode, $name, $description, $price, $wholesale, $cost, $discount, $warranty, $isActive, $isGift, $categoryId, $warehouseId, $stock, $umbral): void {
                    $product = Product::query()->create([
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'name' => $name,
                        'description' => $description,
                        'image_path' => null,
                        'price' => $price,
                        'wholesale_price' => $wholesale,
                        'cost_price' => $cost,
                        'discount_percent' => $discount,
                        'warranty_days' => $warranty,
                        'is_active' => $isActive,
                        'is_gift_card' => $isGift,
                        'category_id' => $categoryId,
                    ]);

                    if ($warehouseId !== null) {
                        $product->warehouses()->sync([
                            $warehouseId => [
                                'stock' => $stock,
                                'umbral' => $umbral,
                                'sale_price' => null,
                                'purchase_price' => null,
                                'unit_id' => null,
                            ],
                        ]);
                    }
                });

                $imported++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $rowNum,
                    'message' => $e->getMessage(),
                ];
            }
            $rowNum++;
        }

        return [
            'imported' => $imported,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @return list<array<int, null|float|int|string>>
     */
    private function readXlsxRows(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $out = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    if (! $row instanceof Row) {
                        continue;
                    }
                    $out[] = $this->normalizeRowArray($row->toArray());
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    /**
     * @return list<array<int, null|float|int|string>>
     */
    private function readCsvRows(string $path): array
    {
        $tryDelimiters = [',', ';', "\t"];
        $best = [];
        foreach ($tryDelimiters as $delimiter) {
            $opts = new CsvOptions;
            $opts->FIELD_DELIMITER = $delimiter;
            $reader = new CsvReader($opts);
            $reader->open($path);
            $rows = [];
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        if (! $row instanceof Row) {
                            continue;
                        }
                        $rows[] = $this->normalizeRowArray($row->toArray());
                    }
                }
            } finally {
                $reader->close();
            }
            if ($rows === []) {
                continue;
            }
            $headerCols = count($rows[0]);
            if ($headerCols >= 5) {
                return $rows;
            }
            $best = $rows;
        }

        return $best;
    }

    /**
     * @param  array<int, null|bool|float|int|string|\DateTimeInterface>  $cells
     * @return array<int, null|float|int|string>
     */
    private function normalizeRowArray(array $cells): array
    {
        $out = [];
        foreach ($cells as $v) {
            if ($v instanceof \DateTimeInterface) {
                $out[] = $v->format('Y-m-d H:i:s');
            } elseif (is_bool($v)) {
                $out[] = $v ? 'Sí' : 'No';
            } else {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $headerRow
     * @return array<string, int>
     */
    private function buildColumnMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $i => $label) {
            $key = $this->canonicalHeaderKey($this->stringCell($label));
            if ($key !== null) {
                $map[$key] = $i;
            }
        }

        return $map;
    }

    private function canonicalHeaderKey(string $raw): ?string
    {
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $raw)));
        $n = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $n);

        return match ($n) {
            'sku' => 'sku',
            'nombre' => 'nombre',
            'descripcion' => 'descripcion',
            'categoria' => 'categoria',
            'precio final' => 'precio_final',
            'precio mayoreo' => 'precio_mayoreo',
            'precio costo' => 'precio_costo',
            'dto. %', 'dto %', 'dto.%', 'dto%' => 'dto_pct',
            'id almacen' => 'warehouse_id',
            'stock' => 'stock',
            'umbral' => 'umbral',
            'activo' => 'activo',
            'codigo barras' => 'barcode',
            'generar codigo barras' => 'generar_barcode',
            'gift card' => 'gift_card',
            'garantia (dias)' => 'garantia_dias',
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $colMap
     */
    private function assertRequiredColumns(array $colMap): void
    {
        $required = ['nombre' => 'Nombre', 'precio_final' => 'Precio final'];
        foreach ($required as $key => $label) {
            if (! isset($colMap[$key])) {
                throw ValidationException::withMessages([
                    'file' => ['Falta la columna obligatoria en la primera fila: «'.$label.'». Descargá la plantilla oficial.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $headerRow
     */
    private function stripBomFromFirstHeaderCell(array &$headerRow): void
    {
        if ($headerRow === [] || ! is_string($headerRow[0] ?? null)) {
            return;
        }
        $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]) ?? $headerRow[0];
    }

    /**
     * @param  array<int, null|float|int|string>  $line
     * @param  array<string, int>  $colMap
     * @return array<string, mixed>
     */
    private function rowToAssoc(array $line, array $colMap): array
    {
        $assoc = [];
        foreach ($colMap as $key => $idx) {
            $assoc[$key] = $line[$idx] ?? null;
        }

        return $assoc;
    }

    /**
     * @param  array<int, null|float|int|string>  $line
     */
    private function isRowBlank(array $line): bool
    {
        foreach ($line as $c) {
            if ($c === null || $c === '') {
                continue;
            }
            if (is_string($c) && trim($c) === '') {
                continue;
            }
            if (is_numeric($c) && (float) $c === 0.0) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function stringCell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_numeric($v)) {
            return trim((string) $v);
        }

        return trim((string) $v);
    }

    private function parseDecimal(mixed $v, string $label): string
    {
        $s = $this->stringCell($v);
        if ($s === '') {
            throw new \RuntimeException($label.' es obligatorio.');
        }
        $s = $this->normalizeDecimalString($s);
        if (! is_numeric($s)) {
            throw new \RuntimeException($label.' no es un número válido.');
        }
        if ((float) $s < 0) {
            throw new \RuntimeException($label.' no puede ser negativo.');
        }

        return (string) $s;
    }

    private function parseOptionalDecimal(mixed $v): ?string
    {
        $s = $this->stringCell($v);
        if ($s === '') {
            return null;
        }
        $s = $this->normalizeDecimalString($s);
        if (! is_numeric($s)) {
            throw new \RuntimeException('Precio no válido.');
        }
        if ((float) $s < 0) {
            throw new \RuntimeException('Precio no puede ser negativo.');
        }

        return (string) $s;
    }

    private function normalizeDecimalString(string $s): string
    {
        $s = trim(str_replace(' ', '', $s));
        if ($s === '') {
            return '';
        }
        if (str_contains($s, ',') && ! str_contains($s, '.')) {
            return str_replace(',', '.', $s);
        }

        return str_replace(',', '', $s);
    }

    private function parseInt(mixed $v, int $default): int
    {
        $s = $this->stringCell($v);
        if ($s === '') {
            return $default;
        }
        if (! preg_match('/^-?\d+$/', $s)) {
            throw new \RuntimeException('Valor entero no válido.');
        }

        return (int) $s;
    }

    private function parseOptionalInt(mixed $v): ?int
    {
        $s = $this->stringCell($v);
        if ($s === '') {
            return null;
        }
        if (! preg_match('/^\d+$/', $s)) {
            throw new \RuntimeException('ID almacén debe ser un número entero.');
        }

        return (int) $s;
    }

    private function parseBool(mixed $v, bool $default): bool
    {
        $s = mb_strtolower($this->stringCell($v));
        if ($s === '') {
            return $default;
        }

        return match ($s) {
            'sí', 'si', 's', 'yes', 'y', '1', 'true', 'verdadero', 'v' => true,
            'no', 'n', '0', 'false', 'falso' => false,
            default => $default,
        };
    }

    private function resolveCategoryId(mixed $v): ?int
    {
        $title = $this->stringCell($v);
        if ($title === '') {
            return null;
        }
        $id = Category::query()
            ->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($title)])
            ->value('id');
        if ($id === null) {
            throw new \RuntimeException('Categoría no encontrada: «'.$title.'».');
        }

        return (int) $id;
    }
}
