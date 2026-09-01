<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\PatientCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $patients = Patient::query()
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('patient_code', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(20);

        return response()->json($patients);
    }

    public function store(Request $request, PatientCodeGenerator $codeGenerator): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:patients,phone'],
            'dob' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        $patient = Patient::create([
            ...$data,
            'patient_code' => $codeGenerator->generate(),
        ]);

        return response()->json($patient, 201);
    }

    public function show(Patient $patient): JsonResponse
    {
        return response()->json($patient->load(['appointments.doctor', 'queueTokens.doctor']));
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string']]);

        $patient = Patient::where('phone', 'like', '%'.$request->phone.'%')
            ->orWhere('patient_code', 'like', '%'.$request->phone.'%')
            ->limit(10)
            ->get();

        return response()->json($patient);
    }
}
