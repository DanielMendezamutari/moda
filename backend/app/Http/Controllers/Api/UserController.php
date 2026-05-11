<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    /** @var list<string> */
    private const AVATAR_PRESETS = [
        'avatar-1', 'avatar-2', 'avatar-3', 'avatar-4',
        'avatar-5', 'avatar-6', 'avatar-7', 'avatar-8',
    ];

    /**
     * Lista usuarios con sucursal y rol(es) del guard `api` (solo administradores).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $users = User::query()
            ->with(['branch:id,name,code'])
            ->orderBy('name')
            ->get();

        $data = $users->map(fn (User $user) => $this->serializeUser($user));

        return response()->json(['data' => $data]);
    }

    /**
     * Alta de usuario (multipart FormData): rol, sucursal, género, activo, documento, foto o avatar plantilla.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $guard = 'api';

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,16}$/'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'document_number' => ['required', 'string', 'max:32', Rule::unique('users', 'document_number')],
            'gender' => ['required', 'string', Rule::in(['male', 'female'])],
            'is_active' => ['required', 'boolean'],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')->where(static fn ($q) => $q->where('guard_name', $guard)),
            ],
            'avatar' => ['nullable', 'image', 'max:4096'],
            'avatar_preset' => ['nullable', 'string', Rule::in(self::AVATAR_PRESETS)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'pin' => $validated['pin'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'document_number' => $validated['document_number'],
            'gender' => $validated['gender'],
            'is_active' => $validated['is_active'],
            'avatar_preset' => null,
            'avatar_upload_path' => null,
        ]);

        $user->assignRole($validated['role']);

        $this->applyAvatarFromRequest($request, $user);

        $user->save();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user->load(['branch:id,name,code']);

        return response()->json([
            'data' => $this->serializeUser($user),
        ], 201);
    }

    /**
     * Actualiza usuario vía FormData (PATCH multipart).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $guard = 'api';

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4,16}$/'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'document_number' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('users', 'document_number')->ignore($user->id),
            ],
            'gender' => ['sometimes', 'required', 'string', Rule::in(['male', 'female'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'role' => [
                'sometimes',
                'required',
                'string',
                Rule::exists('roles', 'name')->where(static fn ($q) => $q->where('guard_name', $guard)),
            ],
            'avatar' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'avatar_preset' => ['sometimes', 'nullable', 'string', Rule::in(self::AVATAR_PRESETS)],
        ]);

        if (isset($validated['role'])) {
            if ($user->hasRole('admin') && $validated['role'] !== 'admin') {
                if (User::role('admin')->count() <= 1) {
                    abort(422, 'No se puede quitar el rol de administrador al último administrador.');
                }
            }
            $user->syncRoles([$validated['role']]);
        }

        if (array_key_exists('name', $validated))
            $user->name = $validated['name'];

        if (array_key_exists('email', $validated))
            $user->email = $validated['email'];

        if (array_key_exists('branch_id', $validated))
            $user->branch_id = $validated['branch_id'];

        if (array_key_exists('document_number', $validated))
            $user->document_number = $validated['document_number'];

        if (array_key_exists('gender', $validated))
            $user->gender = $validated['gender'];

        if (array_key_exists('is_active', $validated))
            $user->is_active = $validated['is_active'];

        if (! empty($validated['password']))
            $user->password = $validated['password'];

        if ($request->exists('pin')) {
            $pin = $request->input('pin');
            $user->pin = ($pin === '' || $pin === null) ? null : $pin;
        }

        $this->applyAvatarFromRequest($request, $user);

        $user->save();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user->refresh();
        $user->load(['branch:id,name,code']);

        return response()->json([
            'data' => $this->serializeUser($user),
        ]);
    }

    /**
     * Elimina un usuario (no a sí mismo ni al último administrador).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        if ($user->id === $request->user()->id) {
            abort(422, 'No podés eliminar tu propio usuario.');
        }

        if ($user->hasRole('admin') && User::role('admin')->count() <= 1) {
            abort(422, 'No se puede eliminar el último administrador.');
        }

        $this->deleteStoredAvatar($user);

        $user->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Usuario eliminado.']);
    }

    private function applyAvatarFromRequest(Request $request, User $user): void
    {
        if ($request->hasFile('avatar')) {
            $this->deleteStoredAvatar($user);

            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_upload_path = $path;
            $user->avatar_preset = null;

            return;
        }

        if (! $request->has('avatar_preset')) {
            return;
        }

        $preset = $request->input('avatar_preset');

        if ($preset === null || $preset === '') {
            $user->avatar_preset = null;

            return;
        }

        if (! in_array($preset, self::AVATAR_PRESETS, true)) {
            return;
        }

        $this->deleteStoredAvatar($user);
        $user->avatar_upload_path = null;
        $user->avatar_preset = $preset;
    }

    private function deleteStoredAvatar(User $user): void
    {
        if ($user->avatar_upload_path && Storage::disk('public')->exists($user->avatar_upload_path))
            Storage::disk('public')->delete($user->avatar_upload_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $user->loadMissing(['branch:id,name,code']);

        $roles = $user->getRoleNames()->values()->all();
        $primary = $roles[0] ?? null;

        $gender = $user->gender;

        $avatarUrl = null;
        if ($user->avatar_upload_path)
            $avatarUrl = asset('storage/'.$user->avatar_upload_path);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'document_number' => $user->document_number,
            'gender' => $gender,
            'gender_label' => $gender === 'female' ? 'Femenino' : ($gender === 'male' ? 'Masculino' : null),
            'is_active' => (bool) $user->is_active,
            'branch_id' => $user->branch_id,
            'branch' => $user->branch ? [
                'id' => $user->branch->id,
                'name' => $user->branch->name,
                'code' => $user->branch->code,
            ] : null,
            'roles' => $roles,
            'role' => $primary,
            'role_display' => $primary ? Str::title(str_replace('_', ' ', $primary)) : null,
            'avatar_url' => $avatarUrl,
            'avatar_preset' => $user->avatar_preset,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
