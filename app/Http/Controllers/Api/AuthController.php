<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PatientCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function sendOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $result = $otpService->send($data['phone']);

        return response()->json($result);
    }

    public function verifyOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'size:6'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $otpService->verify($data['phone'], $data['code'])) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        $user = User::firstOrCreate(
            ['phone' => $data['phone']],
            [
                'name' => $data['name'] ?? 'Patient',
                'role' => UserRole::Patient,
                'status' => 'active',
            ]
        );

        $patient = Patient::firstOrCreate(
            ['phone' => $data['phone']],
            [
                'user_id' => $user->id,
                'patient_code' => app(PatientCodeGenerator::class)->generate(),
                'name' => $data['name'] ?? $user->name,
            ]
        );

        if (! $patient->user_id) {
            $patient->update(['user_id' => $user->id]);
        }

        $token = $user->createToken('patient-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('patient'),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        if ($user->role === UserRole::Doctor) {
            $doctor = $user->doctor;
            if (! $doctor || $doctor->status->value !== 'active') {
                return response()->json(['message' => 'Doctor account is not approved yet.'], 403);
            }
        }

        $token = $user->createToken('staff-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load(['patient', 'doctor']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load(['patient', 'doctor']));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /** Dev-only: instant login without OTP/password. Remove before production. */
    public function devLogin(string $role): JsonResponse
    {
        if (! app()->environment('local')) {
            return response()->json(['message' => 'Not available.'], 404);
        }

        $user = match ($role) {
            'admin' => User::where('email', 'admin@clinic.com')->first(),
            'receptionist' => User::where('email', 'reception@clinic.com')->first(),
            'doctor' => User::where('email', 'doctor@clinic.com')->first(),
            'patient' => $this->devPatientUser(),
            default => null,
        };

        if (! $user) {
            return response()->json(['message' => 'Dev user not found. Run: php artisan migrate --seed'], 404);
        }

        $token = $user->createToken('dev-bypass')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load(['patient', 'doctor']),
        ]);
    }

    private function devPatientUser(): User
    {
        $patient = Patient::where('phone', '9876543210')->first()
            ?? Patient::firstOrFail();

        $user = User::firstOrCreate(
            ['phone' => $patient->phone],
            [
                'name' => $patient->name,
                'role' => UserRole::Patient,
                'status' => 'active',
            ]
        );

        if (! $patient->user_id) {
            $patient->update(['user_id' => $user->id]);
        }

        return $user;
    }
}
