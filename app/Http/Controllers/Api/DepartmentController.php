<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['clinic_id' => ['required', 'integer', 'exists:clinics,id']]);

        $departments = Department::where('clinic_id', $request->clinic_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($departments);
    }
}
