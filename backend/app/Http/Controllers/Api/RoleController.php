<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    private const SYSTEM_ROLE_NAMES = ['admin', 'cashier'];

    /**
     * Lista roles del guard `api` con sus permisos (solo administradores).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $guard = 'api';

        $data = Role::query()
            ->where('guard_name', $guard)
            ->with(['permissions:id,name,guard_name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->serializeRole($role, $guard));

        return response()->json(['data' => $data]);
    }

    /**
     * Crea un rol en el guard `api` y opcionalmente asigna permisos existentes (solo administradores).
     */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $guard = 'api';

        if (in_array($request->input('name'), self::SYSTEM_ROLE_NAMES, true)) {
            abort(422, 'Ese identificador de rol está reservado para el sistema.');
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('roles', 'name')->where(static fn ($q) => $q->where('guard_name', $guard)),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => $guard,
        ]);

        $permissionNames = $validated['permissions'] ?? [];

        $permissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $permissionNames)
            ->get();

        $role->syncPermissions($permissions);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role->refresh();
        $role->load(['permissions:id,name,guard_name']);

        return response()->json([
            'data' => $this->serializeRole($role, $guard),
        ], 201);
    }

    /**
     * Actualiza nombre (salvo roles base) y permisos del rol.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $guard = 'api';

        if ($role->guard_name !== $guard) {
            abort(404, 'No encontrado.');
        }

        $system = $this->isSystemRole($role);

        $rules = [
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ];

        if ($system) {
            $rules['name'] = ['prohibited'];
        } else {
            $rules['name'] = [
                'sometimes',
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('roles', 'name')
                    ->where(static fn ($q) => $q->where('guard_name', $guard))
                    ->ignore($role->id),
            ];
        }

        $validated = $request->validate($rules);

        if (! $system && isset($validated['name'])) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (array_key_exists('permissions', $validated)) {
            $permissionNames = $validated['permissions'] ?? [];

            $permissions = Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $permissionNames)
                ->get();

            $role->syncPermissions($permissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role->refresh();
        $role->load(['permissions:id,name,guard_name']);

        return response()->json([
            'data' => $this->serializeRole($role, $guard),
        ]);
    }

    /**
     * Elimina un rol personalizado (no admin/cajero ni con usuarios asignados).
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        if ($role->guard_name !== 'api') {
            abort(404, 'No encontrado.');
        }

        if ($this->isSystemRole($role)) {
            abort(422, 'No se pueden eliminar los roles base administrador y cajero.');
        }

        if ($role->users()->count() > 0) {
            abort(422, 'No se puede eliminar el rol mientras haya usuarios con ese rol asignado.');
        }

        $role->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Rol eliminado.']);
    }

    private function isSystemRole(Role $role): bool
    {
        return in_array($role->name, self::SYSTEM_ROLE_NAMES, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRole(Role $role, string $guard): array
    {
        $role->loadMissing(['permissions:id,name,guard_name']);

        $permissions = $role->permissions
            ->where('guard_name', $guard)
            ->pluck('name')
            ->values()
            ->all();

        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => Str::title(str_replace('_', ' ', $role->name)),
            'permissions' => $permissions,
            'created_at' => $role->created_at?->toIso8601String(),
            'is_system' => $this->isSystemRole($role),
        ];
    }
}
