<?php

namespace App\Http\Controllers\Api;

use App\Enums\DoctorStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DoctorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Doctor::with('availability');

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
            'consultation_fee' => ['nullable', 'numeric', 'min:0'],
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
            'name' => $data['name'],
            'specialization' => $data['specialization'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'consultation_fee' => $data['consultation_fee'] ?? null,
            'status' => DoctorStatus::Pending,
        ]);

        return response()->json($doctor->load('user'), 201);
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
}
