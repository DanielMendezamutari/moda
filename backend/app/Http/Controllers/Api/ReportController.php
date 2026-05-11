<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Report::class);

        return response()->json([
            'message' => 'Reports placeholder — sustituir por consultas reales de negocio.',
        ]);
    }
}
