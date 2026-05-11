@php
    $fmt = static fn ($s) => is_numeric($s) ? number_format((float) $s, 2, ',', '.') : ($s ?? '—');
    $isClosed = $session->status === 'closed';
    $biz = config('app.name', 'Sistema');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caja turno #{{ $session->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #111;
            margin: 0;
            padding: 18px 22px 28px;
            line-height: 1.35;
        }
        h1 {
            font-size: 16px;
            margin: 0 0 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid #333;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .muted { color: #444; font-size: 9px; }
        table.meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.meta td { padding: 3px 8px 3px 0; vertical-align: top; }
        table.meta td.lbl { font-weight: bold; width: 34%; }
        table.grid {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 14px;
            font-size: 9px;
        }
        table.grid th, table.grid td {
            border: 1px solid #ccc;
            padding: 6px 8px;
            text-align: left;
        }
        table.grid th { background: #f0f0f0; }
        table.grid td.num { text-align: right; font-weight: bold; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            margin: 14px 0 6px;
            border-bottom: 1px solid #333;
            padding-bottom: 2px;
        }
        .highlight {
            margin-top: 16px;
            padding: 10px 12px;
            border: 2px solid #111;
            font-size: 11px;
        }
        .highlight-row { display: table; width: 100%; margin: 4px 0; }
        .highlight-row span { display: table-cell; }
        .highlight-row span:last-child { text-align: right; font-weight: bold; }
        .footer {
            margin-top: 22px;
            font-size: 8px;
            color: #555;
            text-align: center;
        }
    </style>
</head>
<body>

    <h1>Informe de {{ $isClosed ? 'cierre de caja' : 'estado de turno' }}</h1>
    <div class="badge">{{ $isClosed ? 'Cierre definitivo' : 'Turno abierto — datos parciales' }}</div>

    <div class="muted">{{ $biz }} · Generado {{ $generatedAt }}</div>

    <table class="meta">
        <tr>
            <td class="lbl">Turno / sesión</td>
            <td>#{{ $session->id }}</td>
        </tr>
        <tr>
            <td class="lbl">Caja</td>
            <td>{{ $session->cashRegister?->name ?? '—' }} @if($session->cashRegister?->code) ({{ $session->cashRegister->code }}) @endif</td>
        </tr>
        <tr>
            <td class="lbl">Sucursal</td>
            <td>{{ $summary['branch_name'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Apertura</td>
            <td>{{ $session->opened_at?->timezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Abierto por</td>
            <td>{{ $session->openedBy?->name ?? '—' }}</td>
        </tr>
        @if($isClosed)
            <tr>
                <td class="lbl">Cierre</td>
                <td>{{ $session->closed_at?->timezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}</td>
            </tr>
            <tr>
                <td class="lbl">Cerrado por</td>
                <td>{{ $session->closedBy?->name ?? '—' }}</td>
            </tr>
        @endif
    </table>

    <div class="section-title">Ventas registradas en este turno</div>
    <table class="grid">
        <tr>
            <th>Cantidad de ventas</th>
            <th class="num">{{ $summary['sales_count'] }}</th>
        </tr>
        <tr>
            <th>Total facturado (Bs.)</th>
            <td class="num">{{ $fmt($summary['sales_total']) }}</td>
        </tr>
    </table>

    <div class="section-title">Cobros por medio de pago</div>
    <table class="grid">
        <tr>
            <th>Medio</th>
            <th class="num">Total (Bs.)</th>
        </tr>
        @forelse(($summary['payments_by_method'] ?? []) as $method => $amt)
            <tr>
                <td>{{ strtoupper((string) $method) }}</td>
                <td class="num">{{ $fmt($amt) }}</td>
            </tr>
        @empty
            <tr><td colspan="2">Sin cobros registrados.</td></tr>
        @endforelse
    </table>

    <div class="section-title">Movimientos de caja</div>
    <table class="grid">
        <tr>
            <th>Concepto</th>
            <th class="num">Bs.</th>
        </tr>
        <tr>
            <td>Ingresos por ventas (movimientos)</td>
            <td class="num">{{ $fmt($summary['movements_from_sales']) }}</td>
        </tr>
        <tr>
            <td>Ingresos manuales</td>
            <td class="num">{{ $fmt($summary['manual_income']) }}</td>
        </tr>
        <tr>
            <td>Egresos manuales (retiros / gastos)</td>
            <td class="num">{{ $fmt($summary['manual_expense']) }}</td>
        </tr>
    </table>

    <div class="section-title">Arqueo de efectivo</div>
    <div class="highlight">
        <div class="highlight-row">
            <span>Fondo inicial (caja chica)</span>
            <span>{{ $fmt($summary['opening_float']) }}</span>
        </div>
        <div class="highlight-row">
            <span>Efectivo esperado en cajón</span>
            <span>{{ $fmt($summary['expected_cash']) }}</span>
        </div>
        @if($isClosed && ($summary['counted_cash'] ?? null) !== null)
            <div class="highlight-row">
                <span>Efectivo contado al cierre</span>
                <span>{{ $fmt($summary['counted_cash']) }}</span>
            </div>
            <div class="highlight-row">
                <span>Diferencia (sobra / falta)</span>
                <span>{{ $fmt($summary['difference_amount']) }}</span>
            </div>
        @endif
    </div>

    @if($isClosed && $session->notes)
        <div class="section-title">Notas del cierre</div>
        <p style="white-space: pre-wrap; font-size: 9px;">{{ $session->notes }}</p>
    @endif

    <div class="footer">
        Documento interno de control de caja · {{ $biz }}
    </div>

</body>
</html>
