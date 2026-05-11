<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    /** @return list<string> */
    private function dimensionKeys(): array
    {
        return array_keys(config('unit_dimensions.dimensions'));
    }

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $units = Unit::query()->orderBy('name')->get();

        return response()->json([
            'data' => $units->map(fn (Unit $u) => $this->serializeUnit($u)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'dimension' => ['required', 'string', Rule::in($this->dimensionKeys())],
            'is_active' => ['required', 'boolean'],
        ]);

        $unit = Unit::query()->create($validated);

        return response()->json([
            'data' => $this->serializeUnit($unit),
        ], 201);
    }

    public function show(Request $request, Unit $unit): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        return response()->json([
            'data' => $this->serializeUnit($unit),
        ]);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'dimension' => ['sometimes', 'required', 'string', Rule::in($this->dimensionKeys())],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        if (array_key_exists('dimension', $validated) && $validated['dimension'] !== $unit->dimension) {
            $hasConversions = UnitConversion::query()
                ->where(static function ($q) use ($unit): void {
                    $q->where('from_unit_id', $unit->id)
                        ->orWhere('to_unit_id', $unit->id);
                })
                ->exists();

            if ($hasConversions) {
                abort(422, 'No se puede cambiar la dimensión mientras la unidad participe en conversiones.');
            }
        }

        $unit->fill($validated);
        $unit->save();

        return response()->json([
            'data' => $this->serializeUnit($unit->fresh()),
        ]);
    }

    public function destroy(Request $request, Unit $unit): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $hasConversions = UnitConversion::query()
            ->where(static function ($q) use ($unit): void {
                $q->where('from_unit_id', $unit->id)
                    ->orWhere('to_unit_id', $unit->id);
            })
            ->exists();

        if ($hasConversions) {
            abort(422, 'No se puede eliminar la unidad mientras existan conversiones que la usen.');
        }

        $unit->delete();

        return response()->json(['message' => 'Unidad eliminada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUnit(Unit $unit): array
    {
        $dims = config('unit_dimensions.dimensions');

        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'description' => $unit->description,
            'dimension' => $unit->dimension,
            'dimension_label' => $dims[$unit->dimension] ?? $unit->dimension,
            'is_active' => (bool) $unit->is_active,
            'created_at' => $unit->created_at?->toIso8601String(),
        ];
    }
}
