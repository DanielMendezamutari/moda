<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Services\UnitConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitConversionController extends Controller
{
    public function __construct(
        private readonly UnitConversionService $conversionService,
    ) {}

    /**
     * Conversiones almacenadas donde participa la unidad (origen o destino).
     */
    public function index(Request $request, Unit $unit): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $rows = UnitConversion::query()
            ->with(['fromUnit:id,name,dimension', 'toUnit:id,name,dimension'])
            ->where(static function ($q) use ($unit): void {
                $q->where('from_unit_id', $unit->id)
                    ->orWhere('to_unit_id', $unit->id);
            })
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (UnitConversion $c) => $this->serializeConversion($c)),
        ]);
    }

    /**
     * Alta: origen = unidad del URL. Solo una relación por par (cualquier sentido).
     * Body: to_unit_id, factor (> 0). Significado: 1 × origen = factor × destino.
     */
    public function store(Request $request, Unit $unit): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'to_unit_id' => [
                'required',
                'integer',
                Rule::notIn([$unit->id]),
                Rule::exists('units', 'id'),
            ],
            'factor' => ['required', 'numeric', 'gt:0'],
        ]);

        $to = Unit::query()->findOrFail($validated['to_unit_id']);

        $this->conversionService->assertSameDimension($unit, $to);

        if ($this->conversionService->pairConflictsWithOther($unit->id, $to->id)) {
            abort(422, 'Ya existe una conversión entre estas dos unidades (directa o inversa). Editá o eliminá la existente; no hace falta duplicar el sentido.');
        }

        $conversion = UnitConversion::query()->create([
            'from_unit_id' => $unit->id,
            'to_unit_id' => $to->id,
            'factor' => $validated['factor'],
        ]);

        $conversion->load(['fromUnit:id,name,dimension', 'toUnit:id,name,dimension']);

        return response()->json([
            'data' => $this->serializeConversion($conversion),
        ], 201);
    }

    /**
     * Calcula cantidad en unidad destino (compras / inventario). Usa fila directa o inversa.
     */
    public function convert(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'from_unit_id' => ['required', 'integer', 'exists:units,id'],
            'to_unit_id' => ['required', 'integer', 'exists:units,id', 'different:from_unit_id'],
            'quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $this->conversionService->convertQuantity(
            (string) $validated['quantity'],
            (int) $validated['from_unit_id'],
            (int) $validated['to_unit_id'],
        );

        if ($result === null) {
            abort(422, 'No hay conversión registrada entre estas unidades (ni directa ni inversa) o las dimensiones no coinciden.');
        }

        return response()->json([
            'data' => [
                'quantity_from' => (string) $validated['quantity'],
                'quantity_to' => $result['quantity_to'],
                'from_unit_id' => $validated['from_unit_id'],
                'to_unit_id' => $validated['to_unit_id'],
                'via' => $result['via'],
                'factor_applied' => $result['factor_applied'],
            ],
        ]);
    }

    public function update(Request $request, UnitConversion $unitConversion): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'from_unit_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('units', 'id'),
            ],
            'to_unit_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('units', 'id'),
            ],
            'factor' => ['sometimes', 'required', 'numeric', 'gt:0'],
        ]);

        $fromId = (int) ($validated['from_unit_id'] ?? $unitConversion->from_unit_id);
        $toId = (int) ($validated['to_unit_id'] ?? $unitConversion->to_unit_id);

        if ($fromId === $toId) {
            abort(422, 'La unidad origen y destino deben ser distintas.');
        }

        $from = Unit::query()->findOrFail($fromId);
        $to = Unit::query()->findOrFail($toId);

        $this->conversionService->assertSameDimension($from, $to);

        if ($this->conversionService->pairConflictsWithOther($fromId, $toId, $unitConversion->id)) {
            abort(422, 'Ya existe otra conversión entre estas dos unidades (directa o inversa).');
        }

        $unitConversion->fill([
            'from_unit_id' => $fromId,
            'to_unit_id' => $toId,
            'factor' => $validated['factor'] ?? $unitConversion->factor,
        ]);
        $unitConversion->save();

        $unitConversion->load(['fromUnit:id,name,dimension', 'toUnit:id,name,dimension']);

        return response()->json([
            'data' => $this->serializeConversion($unitConversion->fresh()),
        ]);
    }

    public function destroy(Request $request, UnitConversion $unitConversion): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $unitConversion->delete();

        return response()->json(['message' => 'Conversión eliminada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversion(UnitConversion $conversion): array
    {
        $fromName = $conversion->fromUnit?->name ?? '';
        $toName = $conversion->toUnit?->name ?? '';
        $factor = (string) $conversion->factor;

        return [
            'id' => $conversion->id,
            'from_unit_id' => $conversion->from_unit_id,
            'to_unit_id' => $conversion->to_unit_id,
            'factor' => $factor,
            'formula' => "1 {$fromName} = {$factor} {$toName}",
            'from_unit' => $conversion->fromUnit ? [
                'id' => $conversion->fromUnit->id,
                'name' => $conversion->fromUnit->name,
                'dimension' => $conversion->fromUnit->dimension,
            ] : null,
            'to_unit' => $conversion->toUnit ? [
                'id' => $conversion->toUnit->id,
                'name' => $conversion->toUnit->name,
                'dimension' => $conversion->toUnit->dimension,
            ] : null,
            'created_at' => $conversion->created_at?->toIso8601String(),
        ];
    }
}
