<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class StoreAuthController extends Controller
{
    /**
     * Registro de cliente para la tienda en línea (rol `store_customer`).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'surname' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);

        $branch = Branch::query()->where('is_active', true)->orderBy('id')->first();
        if ($branch === null) {
            abort(503, 'No hay sucursal activa para registrar clientes. Contactá al administrador.');
        }

        $doc = 'WEB-'.Str::upper(Str::ulid());

        $user = DB::transaction(function () use ($validated, $branch, $doc): User {
            $user = User::query()->create([
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'branch_id' => null,
                'is_active' => true,
            ]);
            $user->assignRole('store_customer');

            Client::query()->create([
                'name' => trim($validated['name']),
                'surname' => isset($validated['surname']) ? trim((string) $validated['surname']) : null,
                'phone' => isset($validated['phone']) ? trim((string) $validated['phone']) : null,
                'email' => $validated['email'],
                'type_client' => 'natural',
                'type_document' => 'OTRO',
                'n_document' => $doc,
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'is_active' => true,
                'credit_enabled' => false,
                'credit_limit' => null,
                'credit_balance' => 0,
            ]);

            return $user;
        });

        try {
            $token = Auth::guard('api')->login($user);
        } catch (JWTException $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo generar el token de sesión.',
            ], 500);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Login solo para cuentas de tienda (`store_customer`).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta está inactiva.'],
            ]);
        }

        if (! $user->hasRole('store_customer')) {
            throw ValidationException::withMessages([
                'email' => ['Este acceso es solo para clientes de la tienda. El personal debe entrar por «Acceso personal».'],
            ]);
        }

        try {
            $token = Auth::guard('api')->login($user);
        } catch (JWTException $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo generar el token de sesión.',
            ], 500);
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
