<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Mail\PatientRegisteredNotification;
use App\Models\Patient;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PatientCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function sendOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $result = $otpService->send($data['email']);

        return response()->json($result);
    }

    public function verifyOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! $otpService->verify($data['email'], $data['code'])) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        $user = User::where('email', $data['email'])
            ->where('role', UserRole::Patient)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'No account found. Please register first.'], 404);
        }

        $token = $user->createToken('patient-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('patient'),
        ]);
    }

    public function registerPatient(Request $request, OtpService $otpService, PatientCodeGenerator $codeGenerator): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'unique:patients,phone', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:patients,email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'dob' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string'],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'unknown'])],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'code' => ['required_with:email', 'nullable', 'string', 'size:6'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);

        if (! empty($data['email'])) {
            if (empty($data['code']) || ! $otpService->verify($data['email'], $data['code'])) {
                return response()->json(['message' => 'Invalid or expired OTP.'], 422);
            }
        }

        $fullName = trim($data['first_name'].' '.($data['last_name'] ?? ''));

        $user = User::create([
            'name' => $fullName,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role' => UserRole::Patient,
            'status' => 'active',
            'must_reset_password' => false,
        ]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('patient-photos', 'public')
            : null;

        $patient = Patient::create([
            'user_id' => $user->id,
            'patient_code' => $codeGenerator->generate(),
            'registration_source' => 'online',
            'name' => $fullName,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'gender' => $data['gender'] ?? null,
            'dob' => $data['dob'] ?? null,
            'address' => $data['address'] ?? null,
            'blood_group' => $data['blood_group'] ?? null,
            'emergency_contact' => $data['emergency_contact'] ?? null,
            'photo_path' => $photoPath,
        ]);

        $this->notifyRegistration($patient);

        $token = $user->createToken('patient-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('patient'),
        ], 201);
    }

    public function patientLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('role', UserRole::Patient)
            ->where(function ($q) use ($data) {
                $q->where('email', $data['login'])->orWhere('phone', $data['login']);
            })
            ->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $token = $user->createToken('patient-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('patient'),
            'must_reset_password' => $user->must_reset_password,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $requiresCurrent = $user->has_password && ! $user->must_reset_password;

        $data = $request->validate([
            'current_password' => [$requiresCurrent ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($requiresCurrent && ! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update([
            'password' => $data['password'],
            'must_reset_password' => false,
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
            'user' => $user->fresh()->load('patient'),
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
            ['email' => $patient->email ?? 'patient@clinic.com'],
            [
                'name' => $patient->name,
                'phone' => $patient->phone,
                'role' => UserRole::Patient,
                'status' => 'active',
            ]
        );

        if (! $patient->user_id) {
            $patient->update(['user_id' => $user->id]);
        }

        return $user;
    }

    private function notifyRegistration(Patient $patient): void
    {
        $primary = config('clinic.registration_notify_email');
        $fallback = config('clinic.registration_notify_fallback_email');

        if (! $primary && ! $fallback) {
            return;
        }

        try {
            if ($primary) {
                Mail::to($primary)->send(new PatientRegisteredNotification($patient));
            }
        } catch (\Throwable $e) {
            Log::error('Registration notification failed: '.$e->getMessage());

            if ($fallback) {
                Mail::to($fallback)->send(new PatientRegisteredNotification($patient));
            }
        }
    }
}
