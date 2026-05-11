@php
    $showList = $pdfVariant === 'list' || $pdfVariant === 'summary';
    $showStats = $pdfVariant === 'summary';
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
            background: #0d9488;
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
            background: #f0fdfa;
            border: 1px solid #99f6e4;
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
        }
        .stats .num { font-size: 14px; font-weight: bold; color: #0f766e; display: block; }
        .stats .lbl { font-size: 7px; color: #64748b; text-transform: uppercase; }
        h2 {
            font-size: 11px;
            color: #115e59;
            margin: 18px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #5eead4;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        table.data th {
            background: #ccfbf1;
            color: #134e4a;
            font-size: 7px;
            text-transform: uppercase;
            padding: 6px 4px;
            border: 1px solid #99f6e4;
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
        </div>
    </div>

    @if($showStats)
        <table class="stats">
            <tr>
                <td><span class="lbl">Registros (filtro)</span><span class="num">{{ $countAll }}</span></td>
                <td><span class="lbl">Validadas</span><span class="num">{{ $countValidated }}</span></td>
                <td><span class="lbl">Anuladas</span><span class="num">{{ $countCancelled }}</span></td>
                <td><span class="lbl">Total facturado (validadas)</span><span class="num">{{ $sumValidatedTotal }} Bs.</span></td>
            </tr>
        </table>
    @endif

    @if($showList)
        <h2>Detalle</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Ref.</th>
                    <th>Cliente</th>
                    <th>Doc.</th>
                    <th>Total</th>
                    <th>Venta</th>
                    <th>Pago</th>
                    <th>Deuda</th>
                    <th>Usuario</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td>{{ $r['id'] }}</td>
                        <td>{{ $r['reference'] ?: '—' }}</td>
                        <td>{{ $r['client_name'] ?: 'Mostrador' }}</td>
                        <td>{{ $r['n_document'] ?: '—' }}</td>
                        <td>{{ $r['total'] }}</td>
                        <td><span class="badge">{{ $r['state_sale'] }}</span></td>
                        <td>{{ $r['state_payment'] }}</td>
                        <td>{{ $r['debt'] }}</td>
                        <td>{{ $r['user_name'] ?: '—' }}</td>
                        <td>{{ $r['created_at'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10">Sin registros para los filtros actuales.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
