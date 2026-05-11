<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Acceso con correo y contraseña (sin PIN).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta está inactiva.'],
            ]);
        }

        $token = Auth::guard('api')->login($user);

        return $this->respondWithToken($token);
    }

    /**
     * Acceso solo con PIN (terminal POS). El PIN debe poder identificar a un único usuario.
     */
    public function loginWithPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,16}$/'],
        ]);

        $matches = User::query()
            ->whereNotNull('pin')
            ->get()
            ->filter(fn (User $u) => Hash::check($data['pin'], $u->pin));

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'pin' => ['PIN incorrecto.'],
            ]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'pin' => ['Hay varios usuarios con este PIN. Inicia sesión con correo y contraseña.'],
            ]);
        }

        $user = $matches->first();

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'pin' => ['Esta cuenta está inactiva.'],
            ]);
        }

        $token = Auth::guard('api')->login($user);

        return $this->respondWithToken($token);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('roles', 'permissions');

        $isAdmin = $user->hasRole('admin');
        $isCashier = $user->hasRole('cashier');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'branch_id' => $user->branch_id,
            'roles' => $user->getRoleNames()->values()->all(),
            /** Flags explícitos: el SPA usa esto para POS aunque el nombre del rol no sea exactamente `admin`. */
            'is_admin' => $isAdmin,
            'is_cashier' => $isCashier,
            /**
             * Mismo criterio que `SalePolicy`: permiso explícito o rol base POS.
             * Evita 403 en POS cuando el rol `admin` existe pero la tabla de permisos quedó desincronizada.
             */
            'can_pos_sale_create' => $user->can('pos.sale.create') || $isAdmin || $isCashier,
            'can_pos_sale_view' => $user->can('pos.sale.view') || $isAdmin || $isCashier,
            /** Misma idea que compras/POS: rol `admin` aunque los permisos no estén sincronizados en `model_has_permissions`. */
            'can_inventory_kardex_view' => $user->can('inventory.kardex.view') || $isAdmin,
        ]);
    }

    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function refresh(): JsonResponse
    {
        try {
            $token = JWTAuth::parseToken()->refresh();
        } catch (JWTException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        return $this->respondWithToken($token);
    }

    protected function respondWithToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ]);
    }
}
