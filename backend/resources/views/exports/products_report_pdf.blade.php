@php
    $showFullStats = $pdfVariant === 'full';
    $showList = $pdfVariant === 'full' || $pdfVariant === 'list';
    $showStock = $pdfVariant === 'full' || $pdfVariant === 'stock';
    $showBarcodes = $pdfVariant === 'full' || $pdfVariant === 'barcodes';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1e293b;
            margin: 0;
            padding: 16px 20px 24px;
            line-height: 1.35;
        }
        .hero {
            background: #4f46e5;
            color: #fff;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 14px;
        }
        .hero h1 {
            margin: 0 0 4px 0;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 0.02em;
        }
        .hero .sub {
            font-size: 9px;
            opacity: 0.92;
        }
        .stats {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
            margin-bottom: 12px;
        }
        .stats td {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
            width: 25%;
        }
        .stats .num { font-size: 14px; font-weight: bold; color: #4f46e5; display: block; }
        .stats .lbl { font-size: 7px; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; }
        .stats-one td { width: auto; }
        h2 {
            font-size: 11px;
            color: #312e81;
            margin: 18px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #c7d2fe;
        }
        h2 .hint { font-size: 8px; font-weight: normal; color: #64748b; }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        table.data th {
            background: #eef2ff;
            color: #3730a3;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 6px 5px;
            border: 1px solid #c7d2fe;
            text-align: left;
        }
        table.data td {
            padding: 5px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-low { background: #ffedd5; color: #9a3412; }
        .badge-out { background: #fee2e2; color: #991b1b; }
        .barcode-block {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 10px;
            background: #fff;
            page-break-inside: avoid;
        }
        .barcode-block .name { font-weight: bold; font-size: 9px; color: #0f172a; margin-bottom: 2px; }
        .barcode-block .meta { font-size: 8px; color: #64748b; margin-bottom: 6px; }
        .barcode-block .barcode-img {
            display: block;
            margin: 0 auto;
            height: 48px;
            width: auto;
            max-width: 100%;
        }
        .barcode-block .barcode-wrap { text-align: center; }
        .barcode-block .barcode-wrap > div { margin: 0 auto; }
        .barcode-digits {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 10px;
            letter-spacing: 0.12em;
            margin-top: 4px;
            text-align: center;
        }
        .two-col { width: 100%; border-collapse: collapse; }
        .two-col td { width: 50%; vertical-align: top; padding: 0 4px 0 0; }
        .two-col td + td { padding: 0 0 0 4px; }
        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
            font-size: 7px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="hero">
        <h1>{{ $title }}</h1>
        <div class="sub">
            Generado: {{ $generatedAt }}
            @if ($searchLabel)
                · Filtro búsqueda: «{{ $searchLabel }}»
            @else
                · Sin filtro de búsqueda (todos los registros visibles según permisos)
            @endif
        </div>
    </div>

    @if ($showFullStats)
        <table class="stats">
            <tr>
                <td>
                    <span class="num">{{ $totalCount }}</span>
                    <span class="lbl">Productos</span>
                </td>
                <td>
                    <span class="num">{{ $countOptimal }}</span>
                    <span class="lbl">Stock óptimo</span>
                </td>
                <td>
                    <span class="num">{{ $countAttention }}</span>
                    <span class="lbl">Requieren atención</span>
                </td>
                <td>
                    <span class="num">{{ $countBarcodes }}</span>
                    <span class="lbl">Con código de barras</span>
                </td>
            </tr>
        </table>
    @elseif ($pdfVariant === 'list')
        <table class="stats stats-one">
            <tr>
                <td>
                    <span class="num">{{ $totalCount }}</span>
                    <span class="lbl">Productos en el listado</span>
                </td>
            </tr>
        </table>
    @elseif ($pdfVariant === 'stock')
        <table class="stats">
            <tr>
                <td>
                    <span class="num">{{ $totalCount }}</span>
                    <span class="lbl">Productos</span>
                </td>
                <td>
                    <span class="num">{{ $countOptimal }}</span>
                    <span class="lbl">Stock óptimo</span>
                </td>
                <td>
                    <span class="num">{{ $countAttention }}</span>
                    <span class="lbl">Requieren atención</span>
                </td>
                <td>
                    <span class="num">{{ $countBarcodes }}</span>
                    <span class="lbl">Con código de barras</span>
                </td>
            </tr>
        </table>
    @elseif ($pdfVariant === 'barcodes')
        <table class="stats stats-one">
            <tr>
                <td>
                    <span class="num">{{ $countBarcodes }}</span>
                    <span class="lbl">Códigos de barras en este listado</span>
                </td>
            </tr>
        </table>
    @endif

    @if ($showList)
        <h2>Listado general <span class="hint">— SKU, nombre, categoría, precio, stock total, estado</span></h2>
        <table class="data">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th>Precio (Bs.)</th>
                    <th>Activo</th>
                    <th>Stock</th>
                    <th>Almacenes</th>
                    <th>Estado stock</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($generalRows as $r)
                    <tr>
                        <td>{{ $r['sku'] }}</td>
                        <td>{{ $r['nombre'] }}</td>
                        <td>{{ $r['categoria'] }}</td>
                        <td>{{ $r['precio'] }}</td>
                        <td>{{ $r['activo'] }}</td>
                        <td>{{ $r['stock_total'] }}</td>
                        <td>{{ $r['almacenes'] }}</td>
                        <td>
                            @if ($r['stock_status'] === 'optimal')
                                <span class="badge badge-ok">{{ $r['stock_label'] }}</span>
                            @elseif ($r['stock_status'] === 'low')
                                <span class="badge badge-low">{{ $r['stock_label'] }}</span>
                            @else
                                <span class="badge badge-out">{{ $r['stock_label'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($showStock)
        <h2>Nivel de stock <span class="hint">— óptimo vs. bajo umbral / sin stock</span></h2>
        <p style="font-size: 8px; color: #475569; margin: 0 0 8px 0;">
            <strong>Stock óptimo:</strong> hay unidades y ningún almacén con stock ≤ umbral (si el umbral es mayor a 0).
            <strong>Bajo umbral:</strong> al menos un almacén cumple stock ≤ umbral con umbral &gt; 0.
            <strong>Sin stock:</strong> suma total 0.
        </p>

        <h2 style="font-size: 10px; color: #166534; border-bottom-color: #86efac;">En nivel óptimo ({{ count($stockOptimalRows) }})</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Stock total</th>
                    <th>Almacenes</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stockOptimalRows as $r)
                    <tr>
                        <td>{{ $r['sku'] }}</td>
                        <td>{{ $r['nombre'] }}</td>
                        <td>{{ $r['stock_total'] }}</td>
                        <td>{{ $r['almacenes'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:#64748b;">No hay productos en este nivel.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2 style="font-size: 10px; color: #9a3412; border-bottom-color: #fdba74;">Requieren atención ({{ count($stockAttentionRows) }})</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Stock total</th>
                    <th>Almacenes</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stockAttentionRows as $r)
                    <tr>
                        <td>{{ $r['sku'] }}</td>
                        <td>{{ $r['nombre'] }}</td>
                        <td>{{ $r['stock_total'] }}</td>
                        <td>{{ $r['almacenes'] }}</td>
                        <td>{{ $r['stock_label'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:#64748b;">Ningún producto requiere atención por stock.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if ($showBarcodes)
        <h2>Códigos de barras <span class="hint">— representación gráfica</span></h2>
        @if (count($barcodeRows) === 0)
            <p style="color:#64748b;font-size:9px;">No hay productos con código de barras en este listado.</p>
        @else
            <table class="two-col">
                @foreach (array_chunk($barcodeRows, 2) as $pair)
                    <tr>
                        @foreach ($pair as $r)
                            <td>
                                <div class="barcode-block">
                                    <div class="name">{{ $r['nombre'] }}</div>
                                    <div class="meta">SKU: {{ $r['sku'] }}</div>
                                    @if (($r['barcode_bars_html'] ?? '') !== '')
                                        {!! $r['barcode_bars_html'] !!}
                                    @endif
                                    <div class="barcode-digits">{{ $r['barcode'] }}</div>
                                </div>
                            </td>
                        @endforeach
                        @if (count($pair) === 1)
                            <td></td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    @endif

    <div class="footer">
        Documento generado automáticamente · Misma búsqueda y permisos que el listado web.
    </div>
</body>
</html>
