<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    /**
     * ABM de proveedores (solo administradores).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $suppliers = Supplier::query()->orderBy('name')->get();

        return response()->json([
            'data' => $suppliers->map(fn (Supplier $s) => $this->serializeSupplier($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate($this->rulesForStore());

        $supplier = Supplier::query()->create($validated);

        return response()->json([
            'data' => $this->serializeSupplier($supplier),
        ], 201);
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        return response()->json([
            'data' => $this->serializeSupplier($supplier),
        ]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $validated = $request->validate($this->rulesForUpdate($supplier));

        $supplier->fill($validated);
        $supplier->save();

        return response()->json([
            'data' => $this->serializeSupplier($supplier->fresh()),
        ]);
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $supplier->delete();

        return response()->json(['message' => 'Proveedor eliminado.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForStore(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ruc' => ['required', 'string', 'max:32', Rule::unique('suppliers', 'ruc')],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'address' => ['required', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone_alt' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'string', 'max:512'],
            'city' => ['nullable', 'string', 'max:128'],
            'department' => ['nullable', 'string', Rule::in(config('bolivia_departments.departments'))],
            'country' => ['nullable', 'string', 'max:128'],
            'payment_terms' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForUpdate(Supplier $supplier): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'ruc' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('suppliers', 'ruc')->ignore($supplier->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:64'],
            'address' => ['sometimes', 'required', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone_alt' => ['sometimes', 'nullable', 'string', 'max:64'],
            'website' => ['sometimes', 'nullable', 'string', 'max:512'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'department' => ['sometimes', 'nullable', 'string', Rule::in(config('bolivia_departments.departments'))],
            'country' => ['sometimes', 'nullable', 'string', 'max:128'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'ruc' => $supplier->ruc,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'is_active' => (bool) $supplier->is_active,
            'contact_name' => $supplier->contact_name,
            'phone_alt' => $supplier->phone_alt,
            'website' => $supplier->website,
            'city' => $supplier->city,
            'department' => $supplier->department,
            'country' => $supplier->country,
            'payment_terms' => $supplier->payment_terms,
            'notes' => $supplier->notes,
            'created_at' => $supplier->created_at?->toIso8601String(),
        ];
    }
}
