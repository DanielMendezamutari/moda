<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Lista los nombres de permisos del guard `api` (solo administradores).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        $guard = 'api';

        $names = Permission::query()
            ->where('guard_name', $guard)
            ->orderBy('name')
            ->pluck('name')
            ->values();

        return response()->json(['data' => $names]);
    }
}
