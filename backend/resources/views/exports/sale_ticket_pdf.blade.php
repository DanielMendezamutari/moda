@php
    use App\Support\TicketHelpers;

    $tradeName = config('app.name', 'Tienda');
    $fmt = static fn ($n) => number_format((float) $n, 2, ',', '.');
    $fmtQty = static fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', ''), '0'), ',');
    $tz = config('app.timezone', 'UTC');
    $dt = $sale->created_at?->timezone($tz) ?? now()->timezone($tz);
    $fecha = $dt->format('d/m/Y');
    $hora = $dt->format('H:i:s');
    $ref = $sale->reference ?: ('#'.$sale->id);
    $cashReg = $sale->cashRegisterSession?->cashRegister;

    $sumDiscount = $sale->items->sum(static fn ($i) => (float) $i->discount);
    $subtotalNum = (float) $sale->subtotal;
    $discPct = $subtotalNum > 0.00001 ? round($sumDiscount / $subtotalNum * 100, 2) : 0.0;

    $paySum = $sale->payments->sum(static fn ($p) => (float) $p->amount);
    $totalNum = (float) $sale->total;
    $vuelto = max(0, round($paySum - $totalNum, 2));

    $qtyTotal = $sale->items->sum(static fn ($i) => (float) $i->quantity);

    $ambiente = $ticketConf['ambiente'] ?? (app()->environment('production') ? 'PRODUCCIÓN' : 'PRUEBAS');
    $tipoEmision = $ticketConf['tipo_emision'] ?? 'NORMAL';
    $obligado = strtoupper((string) ($ticketConf['obligado_contabilidad'] ?? 'NO'));
    $docFiscal = $ticketConf['documento_fiscal'] ?? null;
    $emailPie = $ticketConf['email'] ?? null;
    $telPie = $ticketConf['telefono'] ?? null;
    $dirExtra = $ticketConf['direccion_linea2'] ?? null;

    $tipoPagoLine = TicketHelpers::tipoPagoResumen($sale->payments);
    $totalWords = TicketHelpers::amountInWordsBolivia($totalNum);

    $consumidorFinal = $sale->client_id === null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>NOTA DE VENTA {{ $ref }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            color: #000;
            margin: 0;
            padding: 6px 5px 12px;
            line-height: 1.22;
            width: 100%;
        }
        .center { text-align: center; }
        .upper { text-transform: uppercase; }
        .bold { font-weight: bold; }
        .doc-title {
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.08em;
            margin: 0 0 4px;
        }
        .trade-name {
            font-size: 10px;
            font-weight: bold;
            margin: 0 0 2px;
        }
        .small { font-size: 7.5px; }
        .muted { color: #222; font-size: 7.5px; }
        .rule {
            border: none;
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        .rule-thick {
            border-top-style: solid;
            border-top-width: 1px;
        }
        .kv { margin: 1px 0; font-size: 8px; }
        .kv-label { font-weight: bold; display: inline; }
        .block-title {
            font-weight: bold;
            font-size: 8.5px;
            margin: 4px 0 3px;
            text-align: center;
            letter-spacing: 0.04em;
        }
        .prod-line-a {
            font-size: 8px;
            margin: 3px 0 0;
            white-space: nowrap;
        }
        .prod-line-name {
            font-size: 8px;
            margin: 1px 0 4px;
            font-weight: bold;
        }
        table.pay-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
            margin-top: 4px;
        }
        table.pay-table td {
            padding: 2px 0;
            vertical-align: top;
        }
        table.pay-table td.lbl { text-align: left; font-weight: bold; }
        table.pay-table td.val { text-align: right; white-space: nowrap; }
        .amount-words {
            font-size: 7.5px;
            margin-top: 6px;
            text-align: center;
            font-weight: bold;
            padding: 4px 2px;
            border: 1px dashed #333;
        }
        .footer-contact {
            margin-top: 6px;
            font-size: 7.5px;
            text-align: center;
        }
        .footer-brand {
            margin-top: 8px;
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            letter-spacing: 0.06em;
        }
    </style>
</head>
<body>

    <div class="center doc-title upper">NOTA DE VENTA</div>
    <div class="center trade-name upper">{{ $tradeName }}</div>

    @if($docFiscal)
        <div class="center small upper">Nº {{ $docFiscal }}</div>
    @endif

    @if($branch)
        @if($branch->address)
            <div class="center small upper">{{ $branch->address }}</div>
        @endif
        @if($dirExtra)
            <div class="center small upper">{{ $dirExtra }}</div>
        @elseif($branch->state)
            <div class="center small upper">{{ $branch->state }}</div>
        @endif
    @elseif($dirExtra)
        <div class="center small upper">{{ $dirExtra }}</div>
    @endif

    <div class="small" style="margin-top:4px;">
        <div>OBLIGADO A LLEVAR CONTABILIDAD: {{ $obligado === 'SI' ? 'SI' : 'NO' }}</div>
        <div>AMBIENTE: {{ $ambiente }}</div>
        <div>EMISIÓN: {{ $tipoEmision }}</div>
    </div>

    <hr class="rule">

    @if($consumidorFinal)
        <div class="center bold upper" style="margin:4px 0;">A CONSUMIDOR FINAL</div>
    @else
        <div class="center bold upper" style="margin:4px 0;">CLIENTE</div>
        <div class="center small">{{ $sale->client->full_name }}</div>
        @if($sale->client->n_document)
            <div class="center muted">Doc.: {{ $sale->client->n_document }}</div>
        @endif
    @endif

    <hr class="rule">

    <div class="kv"><span class="kv-label">Nro Ticket:</span> {{ $ref }}</div>
    <div class="kv"><span class="kv-label">CAJERO:</span> {{ mb_strtoupper($sale->user?->name ?? '—') }}</div>
    <div class="kv"><span class="kv-label">FECHA:</span> {{ $fecha }} &nbsp; <span class="kv-label">HORA:</span> {{ $hora }}</div>
    @if($cashReg)
        <div class="kv muted">
            CAJA: {{ $cashReg->name }}@if($cashReg->code) ({{ $cashReg->code }})@endif
            @if($sale->cash_register_session_id) · Turno #{{ $sale->cash_register_session_id }} @endif
        </div>
    @endif

    <hr class="rule rule-thick">

    <div class="block-title upper">DETALLES DE PRODUCTOS</div>

    @foreach($sale->items as $line)
        @php
            $name = $line->product?->name ?? 'Producto';
            $unit = (float) $line->unit_price;
            $lt = $line->line_total !== null ? (float) $line->line_total : max(0, (float) $line->quantity * $unit - (float) $line->discount);
        @endphp
        <div class="prod-line-a upper">{{ $fmtQty($line->quantity) }} X BS{{ $fmt($unit) }} BS{{ $fmt($lt) }}</div>
        <div class="prod-line-name upper">{{ $name }}</div>
        @if($line->product?->sku)
            <div class="muted" style="margin:-3px 0 4px;">SKU: {{ $line->product->sku }}</div>
        @endif
    @endforeach

    <hr class="rule">

    <div class="kv center bold upper" style="margin-bottom:4px;">TIPO PAGO {{ $tipoPagoLine }}</div>

    <table class="pay-table">
        <tr>
            <td class="lbl">SUBTOTAL</td>
            <td class="val">BS{{ $fmt($sale->subtotal) }}</td>
        </tr>
        <tr>
            <td class="lbl">DESC ({{ $fmt($discPct) }}%)</td>
            <td class="val">BS{{ $fmt($sumDiscount) }}</td>
        </tr>
        <tr>
            <td class="lbl bold" style="padding-top:4px;border-top:1px solid #000;">TOTAL</td>
            <td class="val bold" style="padding-top:4px;border-top:1px solid #000;">BS{{ $fmt($sale->total) }}</td>
        </tr>
    </table>

    @foreach($sale->payments as $p)
        <div class="kv upper" style="margin-top:3px;"><span class="kv-label">PAGO {{ TicketHelpers::paymentLabel($p->method_payment) }}</span></div>
    @endforeach

    <table class="pay-table">
        <tr>
            <td class="lbl">SUMA DE SUS PAGOS</td>
            <td class="val">BS{{ $fmt($paySum) }}</td>
        </tr>
        <tr>
            <td class="lbl">SU VUELTO</td>
            <td class="val">BS{{ $fmt($vuelto) }}</td>
        </tr>
    </table>

    <div class="amount-words upper">{{ $totalWords }}</div>

    <div class="kv center" style="margin-top:6px;"><span class="kv-label">CANTIDAD TOTAL:</span> {{ $fmtQty($qtyTotal) }}</div>

    @if($emailPie || $telPie)
        <div class="footer-contact upper">
            @if($emailPie){{ $emailPie }}@endif
            @if($emailPie && $telPie)<br>@endif
            @if($telPie)TEL: {{ $telPie }}@endif
        </div>
    @endif

    <hr class="rule">
    <hr class="rule" style="margin-top:-3px;">

    <div class="footer-brand upper">{{ $tradeName }}</div>

    <div class="center small" style="margin-top:6px;">GRACIAS POR SU COMPRA</div>

</body>
</html>
