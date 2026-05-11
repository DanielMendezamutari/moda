<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    /**
     * Listado de sucursales (solo administradores).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $branches = Branch::query()->orderBy('name')->get();

        $data = $branches->map(fn (Branch $b) => $this->serializeBranch($b));

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('branches', 'code')],
            'address' => ['required', 'string', 'max:2000'],
            'state' => ['required', 'string', Rule::in(config('bolivia_departments.departments'))],
            'is_active' => ['required', 'boolean'],
        ]);

        $branch = Branch::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'address' => $validated['address'],
            'state' => $validated['state'],
            'is_active' => $validated['is_active'],
        ]);

        return response()->json([
            'data' => $this->serializeBranch($branch),
        ], 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('branches', 'code')->ignore($branch->id),
            ],
            'address' => ['sometimes', 'required', 'string', 'max:2000'],
            'state' => ['sometimes', 'required', 'string', Rule::in(config('bolivia_departments.departments'))],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $branch->fill($validated);
        $branch->save();

        return response()->json([
            'data' => $this->serializeBranch($branch->fresh()),
        ]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        if ($branch->warehouses()->count() > 0) {
            abort(422, 'No se puede eliminar la sucursal mientras haya almacenes asociados.');
        }

        if ($branch->users()->count() > 0) {
            abort(422, 'No se puede eliminar la sucursal mientras haya usuarios asignados.');
        }

        $branch->delete();

        return response()->json(['message' => 'Sucursal eliminada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBranch(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'address' => $branch->address,
            'state' => $branch->state,
            'is_active' => (bool) $branch->is_active,
            'created_at' => $branch->created_at?->toIso8601String(),
        ];
    }
}
