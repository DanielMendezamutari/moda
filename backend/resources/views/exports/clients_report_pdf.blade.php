@php
    $showFull = $pdfVariant === 'full';
    $showList = $pdfVariant === 'list';
    $showDebtors = $pdfVariant === 'debtors';
    $showCreditRisk = $pdfVariant === 'credit-risk';
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
            color: #134e4a;
            margin: 0;
            padding: 16px 20px 24px;
            line-height: 1.35;
        }
        .hero {
            background: #0f766e;
            color: #fff;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 14px;
        }
        .hero h1 {
            margin: 0 0 4px 0;
            font-size: 15px;
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
            border-spacing: 6px;
            margin-bottom: 12px;
        }
        .stats td {
            background: #ecfdf5;
            border: 1px solid #99f6e4;
            border-radius: 6px;
            padding: 8px 8px;
            text-align: center;
            vertical-align: top;
        }
        .stats .num { font-size: 13px; font-weight: bold; color: #0f766e; display: block; }
        .stats .lbl { font-size: 7px; color: #115e59; text-transform: uppercase; letter-spacing: 0.05em; }
        .stats-six td { width: 16.66%; }
        .stats-one td { width: auto; }
        .hint-box {
            background: #f0fdfa;
            border: 1px solid #5eead4;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 8px;
            color: #115e59;
            margin-bottom: 12px;
        }
        h2 {
            font-size: 11px;
            color: #134e4a;
            margin: 16px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #5eead4;
        }
        h2 .hint { font-size: 8px; font-weight: normal; color: #64748b; }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.data th {
            background: #ccfbf1;
            color: #0f766e;
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 6px 4px;
            border: 1px solid #99f6e4;
            text-align: left;
        }
        table.data td {
            padding: 5px 4px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 4px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-warn { background: #ffedd5; color: #9a3412; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-ok { background: #dcfce7; color: #166534; }
        .bracket-head {
            font-size: 10px;
            color: #0f766e;
            margin: 14px 0 6px 0;
            padding-bottom: 2px;
            border-bottom: 1px dashed #99f6e4;
        }
        .footer {
            margin-top: 18px;
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
                · Sin filtro de búsqueda (alcance según permisos y sucursal)
            @endif
        </div>
    </div>

    @if ($showFull)
        <table class="stats stats-six">
            <tr>
                <td>
                    <span class="num">{{ $totalClients }}</span>
                    <span class="lbl">Clientes totales</span>
                </td>
                <td>
                    <span class="num">{{ $withCreditCount }}</span>
                    <span class="lbl">Con crédito habilitado</span>
                </td>
                <td>
                    <span class="num">{{ $debtorsCount }}</span>
                    <span class="lbl">Deudores (saldo &gt; 0)</span>
                </td>
                <td>
                    <span class="num">{{ $totalDebtFormatted }}</span>
                    <span class="lbl">Cartera adeudada (Bs.)</span>
                </td>
                <td>
                    <span class="num">{{ $avgDebtFormatted }}</span>
                    <span class="lbl">Promedio saldo / deudor</span>
                </td>
                <td>
                    <span class="num">{{ $pctDebtorsOfCreditEnabled }} %</span>
                    <span class="lbl">Deudores sobre con crédito</span>
                </td>
            </tr>
        </table>
        <div class="hint-box">
            <strong>Cómo leer el informe:</strong> el tramo de saldo agrupa a los deudores por monto adeudado para ver concentración de riesgo.
            <strong>% cartera</strong> es la parte del saldo total que corresponde a cada tramo.
            Con crédito y sin deuda: <strong>{{ $creditNoDebtCount }}</strong> clientes.
            Alertas de límite (≥80 % o sobre límite): <strong>{{ $highRiskCount }}</strong>.
        </div>

        <h2>Deudores por tramo de saldo <span class="hint">— cantidad, monto y % de la cartera</span></h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Tramo</th>
                    <th>Deudores</th>
                    <th>Suma saldo (Bs.)</th>
                    <th>% del saldo total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bracketSummary as $b)
                    <tr>
                        <td>{{ $b['label'] }}</td>
                        <td>{{ $b['count'] }}</td>
                        <td>{{ $b['sum_formatted'] }}</td>
                        <td>{{ $b['pct_of_portfolio'] }} %</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Mayor exposición <span class="hint">— hasta 25 cuentas con mayor saldo</span></h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Documento</th>
                    <th>Sucursal</th>
                    <th>Saldo</th>
                    <th>Límite</th>
                    <th>% uso</th>
                    <th>Tramo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($topDebtors as $r)
                    <tr>
                        <td>{{ $r['full_name'] }}</td>
                        <td>{{ $r['n_document'] }}</td>
                        <td>{{ $r['branch'] }}</td>
                        <td>{{ $r['balance_fmt'] }}</td>
                        <td>{{ $r['limit_fmt'] }}</td>
                        <td>{{ $r['uso_pct'] }}</td>
                        <td>{{ $r['bracket'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:#64748b;">No hay deudores en este listado.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>Riesgo de línea de crédito <span class="hint">— ≥80 % del límite o sobre límite</span></h2>
        @if (count($riskRows) === 0)
            <p style="color:#64748b;font-size:8px;">Ninguna cuenta en alerta según estos criterios.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Documento</th>
                        <th>Sucursal</th>
                        <th>Saldo</th>
                        <th>Límite</th>
                        <th>% uso</th>
                        <th>Alerta</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($riskRows as $r)
                        <tr>
                            <td>{{ $r['full_name'] }}</td>
                            <td>{{ $r['n_document'] }}</td>
                            <td>{{ $r['branch'] }}</td>
                            <td>{{ $r['balance_fmt'] }}</td>
                            <td>{{ $r['limit_fmt'] }}</td>
                            <td>{{ $r['uso_pct'] }}</td>
                            <td>
                                @if (str_contains($r['alert'], 'Sobre') || str_contains($r['alert'], 'Crítico'))
                                    <span class="badge badge-danger">{{ $r['alert'] }}</span>
                                @elseif (str_contains($r['alert'], 'Alerta'))
                                    <span class="badge badge-warn">{{ $r['alert'] }}</span>
                                @else
                                    {{ $r['alert'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($showList)
        <table class="stats stats-one">
            <tr>
                <td>
                    <span class="num">{{ $totalClients }}</span>
                    <span class="lbl">Clientes en el listado</span>
                </td>
            </tr>
        </table>
        <h2>Listado general</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Documento</th>
                    <th>Sucursal</th>
                    <th>Teléfono</th>
                    <th>Crédito</th>
                    <th>Saldo</th>
                    <th>Límite</th>
                    <th>Activo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($generalListRows as $r)
                    <tr>
                        <td>{{ $r['full_name'] }}</td>
                        <td>{{ $r['n_document'] }}</td>
                        <td>{{ $r['branch'] }}</td>
                        <td>{{ $r['phone'] }}</td>
                        <td>{{ $r['credit_on'] }}</td>
                        <td>{{ $r['balance_fmt'] }}</td>
                        <td>{{ $r['limit_fmt'] }}</td>
                        <td>{{ $r['active'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($showDebtors)
        <table class="stats">
            <tr>
                <td>
                    <span class="num">{{ $debtorsCount }}</span>
                    <span class="lbl">Deudores</span>
                </td>
                <td>
                    <span class="num">{{ $totalDebtFormatted }}</span>
                    <span class="lbl">Saldo total (Bs.)</span>
                </td>
                <td>
                    <span class="num">{{ $avgDebtFormatted }}</span>
                    <span class="lbl">Promedio / deudor</span>
                </td>
                <td>
                    <span class="num">{{ $withCreditCount }}</span>
                    <span class="lbl">Con crédito habilitado</span>
                </td>
            </tr>
        </table>
        <h2>Resumen por tramo</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Tramo</th>
                    <th>Deudores</th>
                    <th>Suma saldo (Bs.)</th>
                    <th>% del saldo total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bracketSummary as $b)
                    <tr>
                        <td>{{ $b['label'] }}</td>
                        <td>{{ $b['count'] }}</td>
                        <td>{{ $b['sum_formatted'] }}</td>
                        <td>{{ $b['pct_of_portfolio'] }} %</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Detalle por tramo</h2>
        @forelse ($debtorsByBracket as $group)
            <div class="bracket-head">{{ $group['label'] }} — {{ count($group['rows']) }} cuenta(s)</div>
            <table class="data">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Documento</th>
                        <th>Sucursal</th>
                        <th>Saldo</th>
                        <th>Límite</th>
                        <th>% uso</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group['rows'] as $r)
                        <tr>
                            <td>{{ $r['full_name'] }}</td>
                            <td>{{ $r['n_document'] }}</td>
                            <td>{{ $r['branch'] }}</td>
                            <td>{{ $r['balance_fmt'] }}</td>
                            <td>{{ $r['limit_fmt'] }}</td>
                            <td>{{ $r['uso_pct'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @empty
            <p style="color:#64748b;">No hay deudores en este listado.</p>
        @endforelse
    @endif

    @if ($showCreditRisk)
        <table class="stats">
            <tr>
                <td>
                    <span class="num">{{ $withCreditCount }}</span>
                    <span class="lbl">Con crédito habilitado</span>
                </td>
                <td>
                    <span class="num">{{ $highRiskCount }}</span>
                    <span class="lbl">En alerta (≥80 % o exceso)</span>
                </td>
                <td>
                    <span class="num">{{ $totalDebtFormatted }}</span>
                    <span class="lbl">Saldo cartera (deudores)</span>
                </td>
            </tr>
        </table>
        <div class="hint-box">
            <strong>% uso</strong> = saldo ÷ límite (solo si hay tope). <strong>Alerta</strong>: ≥80 % del límite; <strong>Crítico</strong>: ≥90 %; <strong>Sobre límite</strong>: saldo mayor al tope.
        </div>
        <h2>Todas las cuentas con crédito</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Documento</th>
                    <th>Sucursal</th>
                    <th>Saldo</th>
                    <th>Límite</th>
                    <th>% uso</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($creditRiskRows as $r)
                    <tr>
                        <td>{{ $r['full_name'] }}</td>
                        <td>{{ $r['n_document'] }}</td>
                        <td>{{ $r['branch'] }}</td>
                        <td>{{ $r['balance_fmt'] }}</td>
                        <td>{{ $r['limit_fmt'] }}</td>
                        <td>{{ $r['uso_pct'] }}</td>
                        <td>
                            @if (str_contains($r['alert'], 'Sobre') || str_contains($r['alert'], 'Crítico'))
                                <span class="badge badge-danger">{{ $r['alert'] }}</span>
                            @elseif (str_contains($r['alert'], 'Alerta'))
                                <span class="badge badge-warn">{{ $r['alert'] }}</span>
                            @elseif ($r['alert'] === 'Normal' || $r['alert'] === 'Al día')
                                <span class="badge badge-ok">{{ $r['alert'] }}</span>
                            @else
                                {{ $r['alert'] }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:#64748b;">Ningún cliente con crédito habilitado.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    <div class="footer">
        Informe para gestión de cartera · Mismos filtros y permisos que el módulo web.
    </div>
</body>
</html>
