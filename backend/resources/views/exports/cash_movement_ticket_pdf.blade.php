@php
    $fmt = static fn ($n) => number_format((float) $n, 2, ',', '.');
    $reg = $m->session?->cashRegister;
    $tipo = $m->type === 'income' ? 'INGRESO' : 'EGRESO';
    $biz = config('app.name', 'Sistema');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Movimiento caja #{{ $m->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #111;
            margin: 0;
            padding: 10px 8px 14px;
            line-height: 1.3;
        }
        .center { text-align: center; }
        .title { font-size: 11px; font-weight: bold; margin: 8px 0 4px; text-transform: uppercase; }
        .rule { border: none; border-top: 1px dashed #333; margin: 8px 0; }
        .row { margin: 3px 0; font-size: 9px; }
        .lbl { font-weight: bold; display: inline-block; min-width: 7rem; }
        .muted { color: #444; font-size: 8px; }
    </style>
</head>
<body>

    <div class="center"><strong>{{ $biz }}</strong></div>
    <div class="center title">Comprobante de movimiento de caja</div>
    <div class="center muted">{{ $generatedAt }}</div>

    <hr class="rule">

    <div class="row"><span class="lbl">Nº movimiento</span> #{{ $m->id }}</div>
    <div class="row"><span class="lbl">Turno</span> #{{ $m->cash_register_session_id }}</div>
    <div class="row"><span class="lbl">Caja</span> {{ $reg?->name ?? '—' }}</div>
    @if($reg?->branch)
        <div class="row"><span class="lbl">Sucursal</span> {{ $reg->branch->name }}</div>
    @endif

    <hr class="rule">

    <div class="row"><span class="lbl">Tipo</span> {{ $tipo }}</div>
    <div class="row"><span class="lbl">Medio</span> {{ strtoupper($m->method_payment) }}</div>
    <div class="row"><span class="lbl">Monto (Bs.)</span> <strong>{{ $fmt($m->amount) }}</strong></div>
    @if($m->description)
        <div class="row"><span class="lbl">Detalle</span> {{ $m->description }}</div>
    @endif
    <div class="row muted">
        Fecha operación: {{ $m->occurred_at?->timezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}
    </div>

    <hr class="rule">
    <div class="center muted">Movimiento manual · No es ticket de venta</div>

</body>
</html>
