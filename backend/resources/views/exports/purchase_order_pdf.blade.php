@php
    $stateLabels = [
        'solicitud' => 'Solicitud',
        'revision' => 'Revisión',
        'parcial' => 'Parcial',
        'entregado' => 'Entregado',
    ];
    $lineStateLabels = [
        'solicitud' => 'Solicitud',
        'entregado' => 'Entregado',
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Orden de compra #{{ $purchase->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1e293b;
            margin: 0;
            padding: 16px 20px 24px;
            line-height: 1.4;
        }
        .hero {
            background: #4338ca;
            color: #fff;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 14px;
        }
        .hero h1 {
            margin: 0 0 4px 0;
            font-size: 16px;
            font-weight: bold;
        }
        .hero .sub { font-size: 9px; opacity: 0.92; }
        .grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .grid td {
            vertical-align: top;
            padding: 4px 8px 4px 0;
            font-size: 9px;
        }
        .grid .lbl {
            color: #64748b;
            font-size: 8px;
            text-transform: uppercase;
        }
        h2 {
            font-size: 11px;
            color: #3730a3;
            margin: 16px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #a5b4fc;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.data th {
            background: #e0e7ff;
            color: #312e81;
            font-size: 7px;
            text-transform: uppercase;
            padding: 6px 4px;
            border: 1px solid #c7d2fe;
            text-align: left;
        }
        table.data td {
            padding: 5px 4px;
            border: 1px solid #e2e8f0;
            font-size: 8px;
        }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .totals {
            width: 100%;
            max-width: 280px;
            margin-left: auto;
            border-collapse: collapse;
        }
        .totals td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            font-size: 9px;
        }
        .totals .lbl { background: #f1f5f9; color: #475569; }
        .totals .num { text-align: right; font-weight: bold; }
        .badge { font-size: 7px; font-weight: bold; }
        .notes {
            margin-top: 12px;
            padding: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Orden de compra #{{ $purchase->id }}</h1>
        <div class="sub">
            Generado: {{ $generatedAt }}
            @if($purchase->date_emision)
                · Emisión: {{ $purchase->date_emision->format('d/m/Y') }}
            @endif
        </div>
    </div>

    <table class="grid">
        <tr>
            <td style="width: 50%;">
                <div class="lbl">Estado</div>
                <div><span class="badge">{{ $stateLabels[$purchase->state] ?? $purchase->state }}</span></div>
            </td>
            <td style="width: 50%;">
                <div class="lbl">Almacén</div>
                <div>{{ $purchase->warehouse?->name ?? '—' }}</div>
                @if($purchase->warehouse?->branch)
                    <div class="text-caption" style="color:#64748b;font-size:8px;">Sucursal: {{ $purchase->warehouse->branch->name }}@if($purchase->warehouse->branch->code) ({{ $purchase->warehouse->branch->code }})@endif</div>
                @endif
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Proveedor</div>
                <div>{{ $purchase->supplier?->name ?? '—' }}</div>
                @if($purchase->supplier?->ruc)
                    <div style="color:#64748b;font-size:8px;">RUC: {{ $purchase->supplier->ruc }}</div>
                @endif
            </td>
            <td>
                <div class="lbl">Solicitante</div>
                <div>{{ $purchase->user?->name ?? '—' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Comprobante</div>
                <div>{{ $purchase->type_comprobant ?: '—' }} @if($purchase->n_comprobant) — {{ $purchase->n_comprobant }} @endif</div>
            </td>
            <td>
                <div class="lbl">Referencia</div>
                <div>{{ $purchase->reference ?: '—' }}</div>
            </td>
        </tr>
    </table>

    @if($purchase->notes)
        <div class="notes">
            <strong>Notas:</strong> {{ $purchase->notes }}
        </div>
    @endif

    <h2>Detalle de líneas</h2>
    <table class="data">
        <thead>
            <tr>
                <th>#</th>
                <th>Producto</th>
                <th>Unidad</th>
                <th style="text-align:right;">Cant.</th>
                <th style="text-align:right;">P. unit.</th>
                <th style="text-align:right;">Subtotal</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchase->items as $idx => $item)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>
                        {{ $item->product?->name ?? '—' }}
                        @if($item->product?->sku)
                            <div style="color:#64748b;font-size:7px;">SKU: {{ $item->product->sku }}</div>
                        @endif
                        @if($item->description)
                            <div style="color:#64748b;font-size:7px;">{{ $item->description }}</div>
                        @endif
                    </td>
                    <td>{{ $item->unit?->name ?? '—' }}</td>
                    <td style="text-align:right;">{{ $item->quantity }}</td>
                    <td style="text-align:right;">{{ $item->unit_cost }}</td>
                    <td style="text-align:right;">{{ $item->line_total ?? '—' }}</td>
                    <td><span class="badge">{{ $lineStateLabels[$item->state] ?? $item->state }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="lbl">Importe (sin IGV)</td>
            <td class="num">{{ $purchase->importe }} Bs.</td>
        </tr>
        <tr>
            <td class="lbl">IGV</td>
            <td class="num">{{ $purchase->igv }} Bs.</td>
        </tr>
        <tr>
            <td class="lbl">Total</td>
            <td class="num" style="font-size:11px;color:#3730a3;">{{ $purchase->total }} Bs.</td>
        </tr>
    </table>
</body>
</html>
