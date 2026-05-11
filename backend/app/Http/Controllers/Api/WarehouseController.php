<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    /**
     * Listado de almacenes con sucursal (solo administradores).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $rows = Warehouse::query()
            ->with(['branch:id,name,code'])
            ->orderBy('name')
            ->get();

        $data = $rows->map(fn (Warehouse $w) => $this->serializeWarehouse($w));

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:2000'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'state' => ['required', 'string', Rule::in(config('bolivia_departments.departments'))],
        ]);

        $warehouse = Warehouse::create($validated);

        $warehouse->load(['branch:id,name,code']);

        return response()->json([
            'data' => $this->serializeWarehouse($warehouse),
        ], 201);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'required', 'string', 'max:2000'],
            'branch_id' => ['sometimes', 'required', 'integer', 'exists:branches,id'],
            'state' => ['sometimes', 'required', 'string', Rule::in(config('bolivia_departments.departments'))],
        ]);

        $warehouse->fill($validated);
        $warehouse->save();

        $warehouse->load(['branch:id,name,code']);

        return response()->json([
            'data' => $this->serializeWarehouse($warehouse->fresh()),
        ]);
    }

    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $warehouse->delete();

        return response()->json(['message' => 'Almacén eliminado.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWarehouse(Warehouse $warehouse): array
    {
        $warehouse->loadMissing(['branch:id,name,code']);

        $b = $warehouse->branch;

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'address' => $warehouse->address,
            'branch_id' => $warehouse->branch_id,
            'state' => $warehouse->state,
            'branch' => $b ? [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
            ] : null,
            'created_at' => $warehouse->created_at?->toIso8601String(),
        ];
    }
}
