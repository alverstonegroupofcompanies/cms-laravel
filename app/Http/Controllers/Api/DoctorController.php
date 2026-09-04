<?php

namespace App\Http\Controllers\Api;

use App\Enums\DoctorStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DoctorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Doctor::with(['availability', 'clinic', 'department']);

        if ($request->filled('clinic_id')) {
            $query->where('clinic_id', $request->clinic_id);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if (! $request->user() || ! $request->user()->hasRole(UserRole::Admin, UserRole::Receptionist)) {
            $query->where('status', DoctorStatus::Active);
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'specialization' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'unique:doctors,email'],
            'clinic_id' => ['nullable', 'integer', 'exists:clinics,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'consultation_fee' => ['nullable', 'numeric', 'min:0'],
            'lunch_start' => ['nullable', 'date_format:H:i'],
            'lunch_end' => ['nullable', 'date_format:H:i', 'after:lunch_start'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password'] ?? 'password123'),
            'role' => UserRole::Doctor,
            'status' => 'active',
        ]);

        $doctor = Doctor::create([
            'user_id' => $user->id,
            'clinic_id' => $data['clinic_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'name' => $data['name'],
            'specialization' => $data['specialization'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'consultation_fee' => $data['consultation_fee'] ?? null,
            'lunch_start' => isset($data['lunch_start']) ? $data['lunch_start'].':00' : null,
            'lunch_end' => isset($data['lunch_end']) ? $data['lunch_end'].':00' : null,
            'status' => DoctorStatus::Pending,
        ]);

        return response()->json($doctor->load(['user', 'clinic', 'department']), 201);
    }

    public function approve(Doctor $doctor): JsonResponse
    {
        $doctor->update(['status' => DoctorStatus::Active]);

        return response()->json($doctor);
    }

    public function deactivate(Doctor $doctor): JsonResponse
    {
        $doctor->update(['status' => DoctorStatus::Inactive]);

        return response()->json($doctor);
    }

    public function setAvailability(Request $request, Doctor $doctor): JsonResponse
    {
        $data = $request->validate([
            'availability' => ['required', 'array'],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.start_time' => ['required', 'date_format:H:i'],
            'availability.*.end_time' => ['required', 'date_format:H:i', 'after:availability.*.start_time'],
            'availability.*.slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:60'],
        ]);

        $doctor->availability()->delete();

        foreach ($data['availability'] as $slot) {
            $doctor->availability()->create($slot);
        }

        return response()->json($doctor->load('availability'));
    }

    public function getAvailability(Doctor $doctor): JsonResponse
    {
        return response()->json($doctor->availability);
    }

    public function availableDates(Request $request, Doctor $doctor, SlotGeneratorService $slotService): JsonResponse
    {
        $data = $request->validate([
            'days' => ['sometimes', 'integer', 'min:7', 'max:60'],
        ]);

        return response()->json([
            'dates' => $slotService->getAvailableDates($doctor->id, (int) ($data['days'] ?? 28)),
        ]);
    }
}
