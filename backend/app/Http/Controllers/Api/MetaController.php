<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    /**
     * Departamentos de Bolivia para selects del frontend.
     */
    public function boliviaDepartments(): JsonResponse
    {
        return response()->json([
            'data' => config('bolivia_departments.departments'),
        ]);
    }

    /**
     * Dimensiones de unidad (clave → etiqueta) para selects del frontend.
     */
    public function unitDimensions(): JsonResponse
    {
        return response()->json([
            'data' => config('unit_dimensions.dimensions'),
        ]);
    }
}
