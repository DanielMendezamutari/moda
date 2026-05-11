<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashRegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashRegister::class);

        $q = CashRegister::query()
            ->with(['branch:id,name,code', 'defaultUser:id,name'])
            ->where('is_active', true);

        if (! $request->user()->hasRole('admin')) {
            $bid = $request->user()->branch_id;
            if ($bid === null) {
                return response()->json(['data' => []]);
            }
            $q->where('branch_id', (int) $bid);
        }

        return response()->json([
            'data' => $q->orderBy('name')->get()->map(fn (CashRegister $r) => $this->serializeRegister($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CashRegister::class);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:32'],
            'default_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branchId = (int) $data['branch_id'];
        $this->assertDefaultUserBelongsToBranch(isset($data['default_user_id']) ? (int) $data['default_user_id'] : null, $branchId);

        $register = new CashRegister;
        $register->branch_id = $branchId;
        $register->name = $data['name'];
        $register->code = $data['code'] ?? null;
        $register->default_user_id = $data['default_user_id'] ?? null;
        $register->is_active = $data['is_active'] ?? true;
        $register->save();

        $register->load(['branch:id,name,code', 'defaultUser:id,name']);

        return response()->json(['data' => $this->serializeRegister($register)], 201);
    }

    public function update(Request $request, CashRegister $cashRegister): JsonResponse
    {
        $this->authorize('update', $cashRegister);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'default_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('default_user_id', $data)) {
            $this->assertDefaultUserBelongsToBranch(
                $data['default_user_id'] !== null ? (int) $data['default_user_id'] : null,
                (int) $cashRegister->branch_id,
            );
        }

        $cashRegister->fill($data);
        $cashRegister->save();
        $cashRegister->load(['branch:id,name,code', 'defaultUser:id,name']);

        return response()->json(['data' => $this->serializeRegister($cashRegister)]);
    }

    /**
     * Cajero/cajera asignado: debe ser usuario activo de la misma sucursal que la caja.
     */
    private function assertDefaultUserBelongsToBranch(?int $userId, int $branchId): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'default_user_id' => ['Usuario inválido o inactivo.'],
            ]);
        }

        if ($user->branch_id === null || (int) $user->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'default_user_id' => ['El usuario debe pertenecer a la sucursal de esta caja.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRegister(CashRegister $r): array
    {
        return [
            'id' => $r->id,
            'branch_id' => $r->branch_id,
            'code' => $r->code,
            'name' => $r->name,
            'default_user_id' => $r->default_user_id,
            'is_active' => (bool) $r->is_active,
            'branch' => $r->branch ? [
                'id' => $r->branch->id,
                'name' => $r->branch->name,
                'code' => $r->branch->code ?? null,
            ] : null,
            'default_user' => $r->defaultUser ? [
                'id' => $r->defaultUser->id,
                'name' => $r->defaultUser->name,
            ] : null,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
