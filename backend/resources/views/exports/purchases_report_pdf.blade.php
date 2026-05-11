@php
    $showList = $pdfVariant === 'list' || $pdfVariant === 'summary';
    $showStats = $pdfVariant === 'summary';
    $stateLabels = [
        'solicitud' => 'Solicitud',
        'revision' => 'Revisión',
        'parcial' => 'Parcial',
        'entregado' => 'Entregado',
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
        .stats {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
            margin-bottom: 12px;
        }
        .stats td {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
        }
        .stats .num { font-size: 13px; font-weight: bold; color: #3730a3; display: block; }
        .stats .lbl { font-size: 7px; color: #64748b; text-transform: uppercase; }
        h2 {
            font-size: 11px;
            color: #312e81;
            margin: 18px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #a5b4fc;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
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
            padding: 4px;
            border: 1px solid #e2e8f0;
            font-size: 8px;
        }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .badge { font-size: 7px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>{{ $title }}</h1>
        <div class="sub">
            Generado: {{ $generatedAt }}
            @if(!empty($searchLabel))
                · Búsqueda: {{ $searchLabel }}
            @endif
            @if(!empty($stateFilterLabel))
                · Estado: {{ $stateFilterLabel }}
            @endif
        </div>
    </div>

    @if($showStats)
        <table class="stats">
            <tr>
                <td><span class="lbl">Registros (filtro)</span><span class="num">{{ $countAll }}</span></td>
                <td><span class="lbl">Solicitud</span><span class="num">{{ $byState['solicitud'] ?? 0 }}</span></td>
                <td><span class="lbl">Revisión</span><span class="num">{{ $byState['revision'] ?? 0 }}</span></td>
                <td><span class="lbl">Parcial</span><span class="num">{{ $byState['parcial'] ?? 0 }}</span></td>
                <td><span class="lbl">Entregado</span><span class="num">{{ $byState['entregado'] ?? 0 }}</span></td>
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
                    <th>Proveedor</th>
                    <th>Almacén</th>
                    <th>Solicitante</th>
                    <th>Estado</th>
                    <th>Importe</th>
                    <th>IGV</th>
                    <th>Total</th>
                    <th>Nº comp.</th>
                    <th>Ref.</th>
                    <th>Registro</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td>{{ $r['id'] }}</td>
                        <td>{{ $r['date_emision'] ?: '—' }}</td>
                        <td>{{ $r['supplier_name'] ?: '—' }}</td>
                        <td>{{ $r['warehouse_name'] ?: '—' }}</td>
                        <td>{{ $r['user_name'] ?: '—' }}</td>
                        <td><span class="badge">{{ $stateLabels[$r['state']] ?? $r['state'] }}</span></td>
                        <td>{{ $r['importe'] }}</td>
                        <td>{{ $r['igv'] }}</td>
                        <td>{{ $r['total'] }}</td>
                        <td>{{ $r['n_comprobant'] ?: '—' }}</td>
                        <td>{{ $r['reference'] ?: '—' }}</td>
                        <td>{{ $r['created_at'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="12">Sin registros para los filtros actuales.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
