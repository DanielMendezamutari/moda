@php
    $headerStates = [
        'solicitud' => 'Solicitud',
        'revision_salida' => 'Revisión salida',
        'salida' => 'Salida',
        'llegada' => 'Llegada',
        'revision_llegada' => 'Revisión llegada',
        'entrega' => 'Entrega',
    ];
    $lineStates = [
        'solicitud' => 'Solicitud',
        'salida' => 'Salida',
        'entrega' => 'Entrega',
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Transporte #{{ $transport->id }}</title>
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
            background: #0369a1;
            color: #fff;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 14px;
        }
        .hero h1 { margin: 0 0 4px 0; font-size: 16px; font-weight: bold; }
        .hero .sub { font-size: 9px; opacity: 0.92; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .grid td { vertical-align: top; padding: 4px 8px 4px 0; font-size: 9px; }
        .grid .lbl { color: #64748b; font-size: 8px; text-transform: uppercase; }
        h2 {
            font-size: 11px;
            color: #0c4a6e;
            margin: 16px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #7dd3fc;
        }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data th {
            background: #e0f2fe;
            color: #0c4a6e;
            font-size: 7px;
            text-transform: uppercase;
            padding: 6px 4px;
            border: 1px solid #7dd3fc;
            text-align: left;
        }
        table.data td { padding: 5px 4px; border: 1px solid #e2e8f0; font-size: 8px; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .totals { width: 100%; max-width: 280px; margin-left: auto; border-collapse: collapse; }
        .totals td { padding: 6px 8px; border: 1px solid #e2e8f0; font-size: 9px; }
        .totals .lbl { background: #f1f5f9; color: #475569; }
        .totals .num { text-align: right; font-weight: bold; }
        .badge { font-size: 7px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Orden de transporte / traslado #{{ $transport->id }}</h1>
        <div class="sub">
            Generado: {{ $generatedAt }}
            @if($transport->date_emision)
                · Emisión: {{ $transport->date_emision->format('d/m/Y') }}
            @endif
        </div>
    </div>

    <table class="grid">
        <tr>
            <td style="width: 50%;">
                <div class="lbl">Estado</div>
                <div><span class="badge">{{ $headerStates[$transport->state] ?? $transport->state }}</span></div>
            </td>
            <td style="width: 50%;">
                <div class="lbl">Referencia</div>
                <div>{{ $transport->reference ?: '—' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Almacén origen</div>
                <div>{{ $transport->warehouseStart?->name ?? '—' }}</div>
                @if($transport->warehouseStart?->branch)
                    <div style="color:#64748b;font-size:8px;">{{ $transport->warehouseStart->branch->name }}@if($transport->warehouseStart->branch->code) ({{ $transport->warehouseStart->branch->code }})@endif</div>
                @endif
            </td>
            <td>
                <div class="lbl">Almacén destino</div>
                <div>{{ $transport->warehouseEnd?->name ?? '—' }}</div>
                @if($transport->warehouseEnd?->branch)
                    <div style="color:#64748b;font-size:8px;">{{ $transport->warehouseEnd->branch->name }}@if($transport->warehouseEnd->branch->code) ({{ $transport->warehouseEnd->branch->code }})@endif</div>
                @endif
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="lbl">Registrado por</div>
                <div>{{ $transport->user?->name ?? '—' }}</div>
            </td>
        </tr>
    </table>

    @if($transport->description)
        <div style="margin-bottom:12px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:9px;">
            <strong>Descripción:</strong> {{ $transport->description }}
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
            @foreach($transport->details as $idx => $item)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>
                        {{ $item->product?->name ?? '—' }}
                        @if($item->product?->sku)
                            <div style="color:#64748b;font-size:7px;">SKU: {{ $item->product->sku }}</div>
                        @endif
                    </td>
                    <td>{{ $item->unit?->name ?? '—' }}</td>
                    <td style="text-align:right;">{{ $item->quantity }}</td>
                    <td style="text-align:right;">{{ $item->price_unit }}</td>
                    <td style="text-align:right;">{{ $item->line_total ?? '—' }}</td>
                    <td><span class="badge">{{ $lineStates[$item->state] ?? $item->state }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="lbl">Importe</td>
            <td class="num">{{ $transport->importe }} Bs.</td>
        </tr>
        <tr>
            <td class="lbl">IGV</td>
            <td class="num">{{ $transport->igv }} Bs.</td>
        </tr>
        <tr>
            <td class="lbl">Total</td>
            <td class="num" style="font-size:11px;color:#0369a1;">{{ $transport->total }} Bs.</td>
        </tr>
    </table>
</body>
</html>
