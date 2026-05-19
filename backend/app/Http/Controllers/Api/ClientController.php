<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientCreditTransaction;
use App\Models\User;
use App\Services\ClientCreditService;
use App\Support\ClientDeletionGuard;
use App\Support\TabularExport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientCreditService $creditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $q = $this->clientListQuery($request);
        if ($q === null) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $q->get()->map(fn (Client $c) => $this->serializeClient($c)),
        ]);
    }

    /**
     * Exporta listado (mismos filtros que el índice: sucursal, búsqueda). format: csv | xlsx | docx
     */
    public function export(Request $request): StreamedResponse|Response
    {
        $this->authorizeView($request);

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'docx'], true)) {
            abort(422, 'Formato no soportado. Usá csv, xlsx o docx.');
        }

        $q = $this->clientListQuery($request);
        $clients = $q === null ? collect() : $q->get();

        $labels = $this->clientExportHeaderLabels();
        $rows = $clients->map(fn (Client $c) => $this->clientExportRowOrdered($this->clientToExportRow($c)))->all();

        $baseName = 'clientes-'.now()->format('Y-m-d-His');

        return match ($format) {
            'csv' => TabularExport::csv($labels, $rows, $baseName),
            'xlsx' => TabularExport::xlsx($labels, $rows, $baseName, 'Clientes'),
            'docx' => TabularExport::docx($labels, $rows, $baseName, 'Listado de clientes'),
        };
    }

    /**
     * Informe PDF para decisiones: variant full | list | debtors | credit-risk
     */
    public function exportReportPdf(Request $request): Response
    {
        $this->authorizeView($request);

        $variant = strtolower((string) $request->query('variant', 'full'));
        if (! in_array($variant, ['full', 'list', 'debtors', 'credit-risk'], true)) {
            abort(422, 'Variante PDF no válida. Usá full, list, debtors o credit-risk.');
        }

        $q = $this->clientListQuery($request);
        $clients = $q === null ? collect() : $q->get();
        $clients->loadMissing(['branch:id,name,code', 'user:id,name,email']);

        $search = $request->string('search')->trim()->toString();

        $titles = [
            'full' => 'Informe ejecutivo — clientes y cartera',
            'list' => 'Listado de clientes',
            'debtors' => 'Deudores por tramo de saldo',
            'credit-risk' => 'Cartera — uso de línea y riesgo',
        ];

        $pdfData = $this->buildClientsPdfDataset($clients);

        $html = view('exports.clients_report_pdf', array_merge([
            'pdfVariant' => $variant,
            'title' => $titles[$variant],
            'generatedAt' => now()->format('d/m/Y H:i'),
            'searchLabel' => $search !== '' ? $search : null,
        ], $pdfData))->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $baseName = 'informe-clientes-'.$variant.'-'.now()->format('Y-m-d-His');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$baseName.'.pdf"',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate($this->rulesForStore($request));

        $this->assertCreditLimitConsistent($validated['credit_enabled'] ?? false, $validated['credit_limit'] ?? null);

        $client = new Client;
        $client->fill($validated);
        $client->credit_balance = '0';
        $client->save();

        return response()->json([
            'data' => $this->serializeClient($client->fresh(['branch:id,name,code', 'user:id,name,email'])),
        ], 201);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->authorizeView($request);
        $this->assertClientScope($request, $client);

        return response()->json([
            'data' => $this->serializeClient($client->load(['branch:id,name,code', 'user:id,name,email'])),
        ]);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManage($request);
        $this->assertClientScope($request, $client);

        $validated = $request->validate($this->rulesForUpdate($client));

        if (array_key_exists('credit_enabled', $validated) || array_key_exists('credit_limit', $validated)) {
            $enabled = $validated['credit_enabled'] ?? $client->credit_enabled;
            $limit = array_key_exists('credit_limit', $validated) ? $validated['credit_limit'] : $client->credit_limit;
            $this->assertCreditLimitConsistent((bool) $enabled, $limit);
            if ($limit !== null && (float) $client->credit_balance > (float) $limit + 0.0001) {
                throw ValidationException::withMessages([
                    'credit_limit' => ['El límite no puede ser menor al saldo actual ('.number_format((float) $client->credit_balance, 2, ',', '.').' Bs.).'],
                ]);
            }
        }

        $client->fill($validated);
        $client->save();

        return response()->json([
            'data' => $this->serializeClient($client->fresh(['branch:id,name,code', 'user:id,name,email'])),
        ]);
    }

    public function deletionStatus(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManage($request);
        $this->assertClientScope($request, $client);

        return response()->json([
            'data' => ClientDeletionGuard::analyze($client->fresh()),
        ]);
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManage($request);
        $this->assertClientScope($request, $client);

        $analysis = ClientDeletionGuard::analyze($client->fresh());
        if (! $analysis['can_delete']) {
            throw ValidationException::withMessages([
                'client' => array_column($analysis['blockers'], 'message'),
            ]);
        }

        $client->delete();

        return response()->json(['message' => 'Cliente eliminado.']);
    }

    public function creditMovements(Request $request, Client $client): JsonResponse
    {
        $this->authorizeView($request);
        $this->assertClientScope($request, $client);

        $rows = $client->creditTransactions()
            ->with('creator:id,name')
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json($rows);
    }

    public function creditCharge(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManage($request);
        $this->assertClientScope($request, $client);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference_type' => ['nullable', 'string', 'max:64'],
            'reference_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $tx = $this->creditService->charge(
            $client,
            (string) $data['amount'],
            $data['description'] ?? null,
            $data['reference_type'] ?? null,
            isset($data['reference_id']) ? (int) $data['reference_id'] : null,
            $request->user(),
        );

        return response()->json([
            'data' => [
                'transaction' => $this->serializeTransaction($tx->load('creator:id,name')),
                'client' => $this->serializeClient($client->fresh(['branch:id,name,code', 'user:id,name,email'])),
            ],
        ], 201);
    }

    public function creditPayment(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManage($request);
        $this->assertClientScope($request, $client);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $tx = $this->creditService->payment(
            $client,
            (string) $data['amount'],
            $data['description'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => [
                'transaction' => $this->serializeTransaction($tx->load('creator:id,name')),
                'client' => $this->serializeClient($client->fresh(['branch:id,name,code', 'user:id,name,email'])),
            ],
        ], 201);
    }

    public function creditAdjustment(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManage($request);
        $this->assertClientScope($request, $client);

        $data = $request->validate([
            'amount' => ['required', 'numeric'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $tx = $this->creditService->adjustment(
            $client,
            (string) $data['amount'],
            $data['description'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => [
                'transaction' => $this->serializeTransaction($tx->load('creator:id,name')),
                'client' => $this->serializeClient($client->fresh(['branch:id,name,code', 'user:id,name,email'])),
            ],
        ], 201);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless(
            $this->userCanViewClients($request->user()),
            403,
            'No autorizado.',
        );
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            $this->userCanManageClients($request->user()),
            403,
            'No autorizado.',
        );
    }

    private function assertClientScope(Request $request, Client $client): void
    {
        if ($this->userCanManageClients($request->user())) {
            return;
        }
        $bid = $request->user()->branch_id;
        if ($bid === null || (int) $client->branch_id !== (int) $bid) {
            abort(403, 'No autorizado.');
        }
    }

    private function userCanViewClients(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('admin')
            || $user->can('clients.view')
            || $user->can('clients.manage');
    }

    private function userCanManageClients(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('admin') || $user->can('clients.manage');
    }

    /**
     * @return Builder<Client>|null null = listado vacío (p. ej. cajero sin sucursal)
     */
    private function clientListQuery(Request $request): ?Builder
    {
        if (! $this->userCanManageClients($request->user()) && $request->user()->branch_id === null) {
            return null;
        }

        $q = Client::query()
            ->with(['branch:id,name,code', 'user:id,name,email'])
            ->orderBy('full_name');

        if (! $this->userCanManageClients($request->user())) {
            $q->where('branch_id', (int) $request->user()->branch_id);
        } elseif ($request->filled('branch_id')) {
            $q->where('branch_id', (int) $request->query('branch_id'));
        }

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(static function ($w) use ($like): void {
                $w->where('full_name', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('surname', 'like', $like)
                    ->orWhere('n_document', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('branch', static function ($bq) use ($like): void {
                        $bq->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like);
                    });
            });
        }

        if ($request->boolean('credit_enabled')) {
            $q->where('credit_enabled', true);
        }

        return $q;
    }

    /**
     * @return list<string>
     */
    private function clientExportHeaderLabels(): array
    {
        return [
            'Nombre completo',
            'Nombre',
            'Apellido',
            'Tipo cliente',
            'Tipo documento',
            'Nº documento',
            'Teléfono',
            'Correo',
            'Sucursal',
            'Código sucursal',
            'Activo',
            'Sexo',
            'Nacimiento',
            'Ubigeo',
            'Dirección',
            'Crédito habilitado',
            'Límite crédito',
            'Saldo crédito',
            'Usuario sistema',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function clientToExportRow(Client $client): array
    {
        $client->loadMissing(['branch:id,name,code', 'user:id,name,email']);
        $b = $client->branch;
        $u = $client->user;

        $addr = $client->address;
        if (is_string($addr)) {
            $addr = trim(preg_replace('/\s+/u', ' ', $addr));
        } else {
            $addr = '';
        }

        $typeClient = match ($client->type_client) {
            'juridico' => 'Jurídica',
            'natural' => 'Natural',
            default => (string) $client->type_client,
        };

        $gender = match ($client->gender) {
            'M' => 'Hombre',
            'F' => 'Mujer',
            'otro' => 'Otro',
            default => '',
        };

        return [
            'full_name' => (string) $client->full_name,
            'name' => (string) $client->name,
            'surname' => (string) ($client->surname ?? ''),
            'type_client' => $typeClient,
            'type_document' => (string) $client->type_document,
            'n_document' => (string) $client->n_document,
            'phone' => (string) ($client->phone ?? ''),
            'email' => (string) ($client->email ?? ''),
            'branch' => (string) ($b->name ?? ''),
            'branch_code' => (string) ($b->code ?? ''),
            'active' => $client->is_active ? 'Sí' : 'No',
            'gender' => $gender,
            'birthdate' => $client->birthdate?->format('Y-m-d') ?? '',
            'ubigeo' => (string) ($client->ubigeo ?? ''),
            'address' => $addr,
            'credit_on' => $client->credit_enabled ? 'Sí' : 'No',
            'credit_limit' => $client->credit_limit !== null ? (string) $client->credit_limit : '',
            'credit_balance' => (string) $client->credit_balance,
            'user_name' => (string) ($u->name ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $assoc
     * @return list<string>
     */
    private function clientExportRowOrdered(array $assoc): array
    {
        $keys = [
            'full_name', 'name', 'surname', 'type_client', 'type_document', 'n_document',
            'phone', 'email', 'branch', 'branch_code', 'active', 'gender', 'birthdate',
            'ubigeo', 'address', 'credit_on', 'credit_limit', 'credit_balance', 'user_name',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[] = $assoc[$k] ?? '';
        }

        return $out;
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @return array<string, mixed>
     */
    private function buildClientsPdfDataset(Collection $clients): array
    {
        $totalClients = $clients->count();
        $withCreditCount = $clients->filter(fn (Client $c) => $c->credit_enabled)->count();

        $debtors = $clients->filter(fn (Client $c) => (float) $c->credit_balance > 0.0001);
        $debtorsCount = $debtors->count();
        $totalDebt = (float) $debtors->sum(fn (Client $c) => (float) $c->credit_balance);
        $avgDebt = $debtorsCount > 0 ? $totalDebt / $debtorsCount : 0.0;
        $pctDebtorsOfCreditEnabled = $withCreditCount > 0
            ? round(100 * $debtorsCount / $withCreditCount, 1)
            : 0.0;

        $creditNoDebtCount = $clients->filter(
            fn (Client $c) => $c->credit_enabled && (float) $c->credit_balance <= 0.0001
        )->count();

        $bracketDefs = $this->pdfDebtBracketDefinitions();
        $bracketSummary = [];
        foreach ($bracketDefs as $def) {
            $bracketSummary[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'count' => 0,
                'sum' => 0.0,
                'pct_of_portfolio' => 0.0,
            ];
        }
        $keyToIndex = [];
        foreach ($bracketSummary as $i => $row) {
            $keyToIndex[$row['key']] = $i;
        }

        foreach ($debtors as $c) {
            $bal = (float) $c->credit_balance;
            $key = $this->pdfDebtBracketKey($bal);
            if ($key !== null && isset($keyToIndex[$key])) {
                $i = $keyToIndex[$key];
                $bracketSummary[$i]['count']++;
                $bracketSummary[$i]['sum'] += $bal;
            }
        }

        if ($totalDebt > 0.0001) {
            foreach ($bracketSummary as $i => $row) {
                $bracketSummary[$i]['pct_of_portfolio'] = round(100 * $row['sum'] / $totalDebt, 1);
            }
        }

        foreach ($bracketSummary as $i => $row) {
            $bracketSummary[$i]['sum_formatted'] = $this->pdfMoneyFormat($row['sum']);
        }

        $topDebtors = $debtors->sortByDesc(fn (Client $c) => (float) $c->credit_balance)
            ->take(25)
            ->map(fn (Client $c) => $this->pdfDebtorRow($c))
            ->values()
            ->all();

        $riskRows = $clients
            ->filter(fn (Client $c) => $this->pdfClientIsHighCreditRisk($c))
            ->sortByDesc(fn (Client $c) => $this->pdfCreditUtilizationRatio($c) ?? 0.0)
            ->map(fn (Client $c) => $this->pdfCreditRiskRow($c))
            ->values()
            ->all();

        $creditRiskAll = $clients
            ->filter(fn (Client $c) => $c->credit_enabled)
            ->sortByDesc(fn (Client $c) => (float) $c->credit_balance)
            ->map(fn (Client $c) => $this->pdfCreditRiskRow($c))
            ->values()
            ->all();

        $generalListRows = $clients->sortBy('full_name')
            ->map(fn (Client $c) => $this->pdfClientListRow($c))
            ->values()
            ->all();

        $debtorsByBracket = [];
        foreach ($bracketDefs as $def) {
            $key = $def['key'];
            $inBracket = $debtors
                ->filter(fn (Client $c) => $this->pdfDebtBracketKey((float) $c->credit_balance) === $key)
                ->sortByDesc(fn (Client $c) => (float) $c->credit_balance)
                ->map(fn (Client $c) => $this->pdfDebtorRow($c))
                ->values()
                ->all();
            if (count($inBracket) > 0) {
                $debtorsByBracket[] = [
                    'label' => $def['label'],
                    'rows' => $inBracket,
                ];
            }
        }

        return [
            'totalClients' => $totalClients,
            'withCreditCount' => $withCreditCount,
            'debtorsCount' => $debtorsCount,
            'totalDebt' => $totalDebt,
            'totalDebtFormatted' => $this->pdfMoneyFormat($totalDebt),
            'avgDebtFormatted' => $this->pdfMoneyFormat($avgDebt),
            'pctDebtorsOfCreditEnabled' => $pctDebtorsOfCreditEnabled,
            'creditNoDebtCount' => $creditNoDebtCount,
            'highRiskCount' => count($riskRows),
            'bracketSummary' => $bracketSummary,
            'topDebtors' => $topDebtors,
            'riskRows' => $riskRows,
            'creditRiskRows' => $creditRiskAll,
            'generalListRows' => $generalListRows,
            'debtorsByBracket' => $debtorsByBracket,
        ];
    }

    private function pdfMoneyFormat(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }

    /**
     * Tramos de saldo adeudado (Bs.) para segmentar deudores.
     *
     * @return list<array{key: string, label: string, max: float}>
     */
    private function pdfDebtBracketDefinitions(): array
    {
        return [
            ['key' => 'b1', 'label' => 'Saldo hasta 200 Bs.', 'max' => 200],
            ['key' => 'b2', 'label' => '200,01 – 500 Bs.', 'max' => 500],
            ['key' => 'b3', 'label' => '500,01 – 1.000 Bs.', 'max' => 1000],
            ['key' => 'b4', 'label' => '1.000,01 – 5.000 Bs.', 'max' => 5000],
            ['key' => 'b5', 'label' => 'Más de 5.000 Bs.', 'max' => PHP_FLOAT_MAX],
        ];
    }

    private function pdfDebtBracketKey(float $balance): ?string
    {
        if ($balance <= 0.0001) {
            return null;
        }
        foreach ($this->pdfDebtBracketDefinitions() as $def) {
            if ($balance <= $def['max']) {
                return $def['key'];
            }
        }

        return 'b5';
    }

    private function pdfBracketLabelForKey(?string $key): string
    {
        if ($key === null) {
            return '—';
        }
        foreach ($this->pdfDebtBracketDefinitions() as $def) {
            if ($def['key'] === $key) {
                return $def['label'];
            }
        }

        return '—';
    }

    /**
     * @return array<string, string>
     */
    private function pdfDebtorRow(Client $c): array
    {
        $bal = (float) $c->credit_balance;
        $lim = $c->credit_limit !== null ? (float) $c->credit_limit : null;
        $ratio = $this->pdfCreditUtilizationRatio($c);
        $key = $this->pdfDebtBracketKey($bal);

        return [
            'full_name' => (string) $c->full_name,
            'n_document' => (string) $c->n_document,
            'branch' => (string) ($c->branch?->name ?? '—'),
            'balance_fmt' => $this->pdfMoneyFormat($bal),
            'limit_fmt' => $lim !== null ? $this->pdfMoneyFormat($lim) : 'Sin tope',
            'uso_pct' => $ratio !== null ? (string) round($ratio, 1).' %' : '—',
            'bracket' => $this->pdfBracketLabelForKey($key),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pdfClientListRow(Client $c): array
    {
        $bal = (float) $c->credit_balance;

        return [
            'full_name' => (string) $c->full_name,
            'n_document' => (string) $c->n_document,
            'branch' => (string) ($c->branch?->name ?? '—'),
            'phone' => (string) ($c->phone ?? '—'),
            'credit_on' => $c->credit_enabled ? 'Sí' : 'No',
            'balance_fmt' => $this->pdfMoneyFormat($bal),
            'limit_fmt' => $c->credit_limit !== null ? $this->pdfMoneyFormat((float) $c->credit_limit) : '—',
            'active' => $c->is_active ? 'Sí' : 'No',
        ];
    }

    private function pdfCreditUtilizationRatio(Client $c): ?float
    {
        if (! $c->credit_enabled || $c->credit_limit === null) {
            return null;
        }
        $lim = (float) $c->credit_limit;
        if ($lim <= 0) {
            return null;
        }

        return 100.0 * (float) $c->credit_balance / $lim;
    }

    private function pdfClientIsHighCreditRisk(Client $c): bool
    {
        if (! $c->credit_enabled) {
            return false;
        }
        $bal = (float) $c->credit_balance;
        if ($bal <= 0.0001) {
            return false;
        }
        $lim = $c->credit_limit !== null ? (float) $c->credit_limit : null;
        if ($lim !== null && $lim > 0) {
            if ($bal > $lim + 0.01) {
                return true;
            }
            if ($bal >= $lim * 0.8) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function pdfCreditRiskRow(Client $c): array
    {
        $bal = (float) $c->credit_balance;
        $lim = $c->credit_limit !== null ? (float) $c->credit_limit : null;
        $ratio = $this->pdfCreditUtilizationRatio($c);
        $alert = '—';
        if ($lim !== null && $lim > 0) {
            if ($bal > $lim + 0.01) {
                $alert = 'Sobre límite';
            } elseif ($ratio !== null && $ratio >= 90) {
                $alert = 'Crítico (≥90 %)';
            } elseif ($ratio !== null && $ratio >= 80) {
                $alert = 'Alerta (≥80 %)';
            } else {
                $alert = 'Normal';
            }
        } else {
            $alert = $bal > 0.0001 ? 'Sin tope definido' : 'Al día';
        }

        return [
            'full_name' => (string) $c->full_name,
            'n_document' => (string) $c->n_document,
            'branch' => (string) ($c->branch?->name ?? '—'),
            'balance_fmt' => $this->pdfMoneyFormat($bal),
            'limit_fmt' => $lim !== null ? $this->pdfMoneyFormat($lim) : 'Sin tope',
            'uso_pct' => $ratio !== null ? (string) round($ratio, 1).' %' : '—',
            'alert' => $alert,
        ];
    }

    private function assertCreditLimitConsistent(bool $enabled, mixed $limit): void
    {
        if (! $enabled) {
            return;
        }
        if ($limit !== null && (float) $limit < 0) {
            throw ValidationException::withMessages([
                'credit_limit' => ['El límite de crédito no puede ser negativo.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForStore(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'type_client' => ['required', Rule::in(['natural', 'juridico'])],
            'type_document' => ['required', 'string', 'max:32'],
            'n_document' => [
                'required',
                'string',
                'max:64',
                Rule::unique('clients', 'n_document')->where('branch_id', (int) $request->input('branch_id')),
            ],
            'birthdate' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'is_active' => ['required', 'boolean'],
            'gender' => ['nullable', Rule::in(['M', 'F', 'otro'])],
            'ubigeo' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:2000'],
            'credit_enabled' => ['sometimes', 'boolean'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForUpdate(Client $client): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'surname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'type_client' => ['sometimes', 'required', Rule::in(['natural', 'juridico'])],
            'type_document' => ['sometimes', 'required', 'string', 'max:32'],
            'n_document' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('clients', 'n_document')
                    ->where('branch_id', $client->branch_id)
                    ->ignore($client->id),
            ],
            'birthdate' => ['sometimes', 'nullable', 'date'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['sometimes', 'required', 'integer', 'exists:branches,id'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'gender' => ['sometimes', 'nullable', Rule::in(['M', 'F', 'otro'])],
            'ubigeo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'credit_enabled' => ['sometimes', 'boolean'],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClient(Client $client): array
    {
        $b = $client->branch;
        $u = $client->user;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'surname' => $client->surname,
            'full_name' => $client->full_name,
            'phone' => $client->phone,
            'email' => $client->email,
            'type_client' => $client->type_client,
            'type_document' => $client->type_document,
            'n_document' => $client->n_document,
            'birthdate' => $client->birthdate?->format('Y-m-d'),
            'user_id' => $client->user_id,
            'branch_id' => $client->branch_id,
            'is_active' => (bool) $client->is_active,
            'gender' => $client->gender,
            'ubigeo' => $client->ubigeo,
            'address' => $client->address,
            'credit_enabled' => (bool) $client->credit_enabled,
            'credit_limit' => $client->credit_limit !== null ? (string) $client->credit_limit : null,
            'credit_balance' => (string) $client->credit_balance,
            'branch' => $b ? [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
            ] : null,
            'user' => $u ? [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ] : null,
            'created_at' => $client->created_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(ClientCreditTransaction $t): array
    {
        $c = $t->creator;

        return [
            'id' => $t->id,
            'type' => $t->type,
            'amount' => (string) $t->amount,
            'balance_after' => (string) $t->balance_after,
            'description' => $t->description,
            'reference_type' => $t->reference_type,
            'reference_id' => $t->reference_id,
            'created_by' => $t->created_by,
            'creator' => $c ? ['id' => $c->id, 'name' => $c->name] : null,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
