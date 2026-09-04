<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use Illuminate\Http\JsonResponse;

class ClinicController extends Controller
{
    public function index(): JsonResponse
    {
        $clinics = Clinic::where('is_active', true)
            ->withCount('departments')
            ->orderBy('name')
            ->get();

        return response()->json($clinics);
    }
}
