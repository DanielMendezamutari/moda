@php
    $showList = $pdfVariant === 'list' || $pdfVariant === 'summary';
    $showStats = $pdfVariant === 'summary';
    $stateLabels = [
        'solicitud' => 'Solicitud',
        'revision_salida' => 'Rev. salida',
        'salida' => 'Salida',
        'llegada' => 'Llegada',
        'revision_llegada' => 'Rev. llegada',
        'entrega' => 'Entrega',
    ];
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
            background: #0369a1;
            color: #fff;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 14px;
        }
        .hero h1 { margin: 0 0 4px 0; font-size: 16px; font-weight: bold; }
        .hero .sub { font-size: 9px; opacity: 0.92; }
        .stats { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 12px; }
        .stats td {
            background: #e0f2fe;
            border: 1px solid #7dd3fc;
            border-radius: 6px;
            padding: 6px 8px;
            text-align: center;
        }
        .stats .num { font-size: 11px; font-weight: bold; color: #0369a1; display: block; }
        .stats .lbl { font-size: 6px; color: #64748b; text-transform: uppercase; }
        h2 {
            font-size: 11px;
            color: #0c4a6e;
            margin: 18px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #7dd3fc;
        }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.data th {
            background: #e0f2fe;
            color: #0c4a6e;
            font-size: 7px;
            text-transform: uppercase;
            padding: 6px 4px;
            border: 1px solid #7dd3fc;
            text-align: left;
        }
        table.data td { padding: 4px; border: 1px solid #e2e8f0; font-size: 8px; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .badge { font-size: 7px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>{{ $title }}</h1>
        <div class="sub">
            Generado: {{ $generatedAt }}
            @if(!empty($searchLabel)) · Búsqueda: {{ $searchLabel }} @endif
            @if(!empty($stateFilterLabel)) · Estado: {{ $stateFilterLabel }} @endif
        </div>
    </div>

    @if($showStats)
        <table class="stats">
            <tr>
                <td><span class="lbl">Registros</span><span class="num">{{ $countAll }}</span></td>
                @foreach($byState as $k => $n)
                    <td><span class="lbl">{{ $stateLabels[$k] ?? $k }}</span><span class="num">{{ $n }}</span></td>
                @endforeach
                <td><span class="lbl">Suma totales</span><span class="num">{{ $sumTotal }} Bs.</span></td>
            </tr>
        </table>
    @endif

    @if($showList)
        <h2>Detalle</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Emisión</th>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th>Usuario</th>
                    <th>Estado</th>
                    <th>Importe</th>
                    <th>IGV</th>
                    <th>Total</th>
                    <th>Ref.</th>
                    <th>Registro</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td>{{ $r['id'] }}</td>
                        <td>{{ $r['date_emision'] ?: '—' }}</td>
                        <td>{{ $r['origin'] ?: '—' }}</td>
                        <td>{{ $r['dest'] ?: '—' }}</td>
                        <td>{{ $r['user_name'] ?: '—' }}</td>
                        <td><span class="badge">{{ $stateLabels[$r['state']] ?? $r['state'] }}</span></td>
                        <td>{{ $r['importe'] }}</td>
                        <td>{{ $r['igv'] }}</td>
                        <td>{{ $r['total'] }}</td>
                        <td>{{ $r['reference'] ?: '—' }}</td>
                        <td>{{ $r['created_at'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11">Sin registros para los filtros actuales.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
