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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Doctor::with(['availability', 'clinic', 'department', 'user:id,email,must_reset_password']);

        if ($request->filled('clinic_id')) {
            $query->where('clinic_id', $request->clinic_id);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $user = $request->user('sanctum') ?? $request->user();

        if (! $user || ! $user->isClinicStaff()) {
            $query->where('status', DoctorStatus::Active);
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureStaff($request);

        $data = $this->validateDoctorPayload($request, null);
        $password = $data['password'] ?? 'password123';

        $doctor = DB::transaction(function () use ($request, $data, $password) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $password,
                'role' => UserRole::Doctor,
                'status' => 'active',
                'must_reset_password' => (bool) ($data['must_reset_password'] ?? false),
            ]);

            $doctor = Doctor::create([
                'user_id' => $user->id,
                'clinic_id' => $data['clinic_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'name' => $data['name'],
                'specialization' => $data['specialization'],
                'room_number' => $data['room_number'] ?? null,
                'phone' => $data['phone'],
                'phone_secondary' => $data['phone_secondary'] ?? null,
                'email' => $data['email'],
                'photo_path' => $this->storePhoto($request),
                'consultation_fee' => $data['consultation_fee'] ?? null,
                'lunch_start' => $this->normalizeTime($data['lunch_start'] ?? null),
                'lunch_end' => $this->normalizeTime($data['lunch_end'] ?? null),
                'status' => DoctorStatus::Active,
            ]);

            if (! empty($data['availability'])) {
                $this->replaceAvailability($doctor, $data['availability']);
            }

            return $doctor;
        });

        return response()->json(
            $doctor->load(['user:id,email,must_reset_password', 'clinic', 'department', 'availability']),
            201
        );
    }

    public function update(Request $request, Doctor $doctor): JsonResponse
    {
        $this->ensureStaff($request);

        $data = $this->validateDoctorPayload($request, $doctor);

        DB::transaction(function () use ($request, $doctor, $data) {
            $photoPath = $this->storePhoto($request) ?? $doctor->photo_path;

            $doctor->update([
                'clinic_id' => array_key_exists('clinic_id', $data) ? $data['clinic_id'] : $doctor->clinic_id,
                'department_id' => array_key_exists('department_id', $data) ? $data['department_id'] : $doctor->department_id,
                'name' => $data['name'],
                'specialization' => $data['specialization'],
                'room_number' => array_key_exists('room_number', $data) ? $data['room_number'] : $doctor->room_number,
                'phone' => $data['phone'],
                'phone_secondary' => array_key_exists('phone_secondary', $data)
                    ? ($data['phone_secondary'] ?: null)
                    : $doctor->phone_secondary,
                'email' => $data['email'],
                'photo_path' => $photoPath,
                'consultation_fee' => array_key_exists('consultation_fee', $data)
                    ? $data['consultation_fee']
                    : $doctor->consultation_fee,
                'lunch_start' => array_key_exists('lunch_start', $data)
                    ? $this->normalizeTime($data['lunch_start'] ?? null)
                    : $doctor->lunch_start,
                'lunch_end' => array_key_exists('lunch_end', $data)
                    ? $this->normalizeTime($data['lunch_end'] ?? null)
                    : $doctor->lunch_end,
            ]);

            if ($doctor->user) {
                $doctor->user->update([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                ]);
            }

            if (! empty($data['availability'])) {
                $this->replaceAvailability($doctor, $data['availability']);
            }
        });

        return response()->json(
            $doctor->fresh()->load(['user:id,email,must_reset_password', 'clinic', 'department', 'availability'])
        );
    }

    public function resetPassword(Request $request, Doctor $doctor): JsonResponse
    {
        $this->ensureStaff($request);

        if (! $doctor->user) {
            return response()->json(['message' => 'Doctor has no login account.'], 422);
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
            'must_reset_password' => ['sometimes', 'boolean'],
        ]);

        $doctor->user->update([
            'password' => $data['password'],
            'must_reset_password' => (bool) ($data['must_reset_password'] ?? true),
        ]);

        return response()->json([
            'message' => 'Doctor password updated.',
            'login_email' => $doctor->user->email,
            'must_reset_password' => $doctor->user->must_reset_password,
        ]);
    }

    public function approve(Request $request, Doctor $doctor): JsonResponse
    {
        $this->ensureStaff($request);

        $doctor->update(['status' => DoctorStatus::Active]);

        return response()->json($doctor);
    }

    public function deactivate(Request $request, Doctor $doctor): JsonResponse
    {
        $this->ensureStaff($request);

        $doctor->update(['status' => DoctorStatus::Inactive]);

        return response()->json($doctor);
    }

    public function setAvailability(Request $request, Doctor $doctor): JsonResponse
    {
        $this->ensureStaff($request);

        $data = $request->validate([
            'availability' => ['required', 'array'],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.start_time' => ['required', 'date_format:H:i'],
            'availability.*.end_time' => ['required', 'date_format:H:i', 'after:availability.*.start_time'],
            'availability.*.slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:60'],
        ]);

        $this->replaceAvailability($doctor, $data['availability']);

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

    private function ensureStaff(Request $request): void
    {
        $user = $request->user();
        if (! $user?->isClinicStaff()) {
            $role = $user?->role instanceof UserRole
                ? $user->role->value
                : (string) ($user?->getAttributes()['role'] ?? 'unknown');
            abort(403, "Only clinic staff (receptionist/admin) can manage doctors. Your role is '{$role}'.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDoctorPayload(Request $request, ?Doctor $doctor): array
    {
        $this->normalizeMultipartPayload($request);

        $doctorId = $doctor?->id;
        $userId = $doctor?->user_id;

        $isCreate = $doctor === null;

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'specialization' => ['required', 'string', 'max:255'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:20'],
            'phone_secondary' => ['nullable', 'string', 'max:20'],
            'email' => [
                'required',
                'email',
                Rule::unique('doctors', 'email')->ignore($doctorId),
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'clinic_id' => ['nullable', 'integer', 'exists:clinics,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'consultation_fee' => ['nullable', 'numeric', 'min:0'],
            'lunch_start' => ['nullable', 'date_format:H:i'],
            'lunch_end' => ['nullable', 'date_format:H:i', 'after:lunch_start'],
            'password' => [$isCreate ? 'required' : 'nullable', 'string', 'min:8'],
            'must_reset_password' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'availability' => ['sometimes', 'array'],
            'availability.*.day_of_week' => ['required_with:availability', 'integer', 'between:0,6'],
            'availability.*.start_time' => ['required_with:availability', 'date_format:H:i'],
            'availability.*.end_time' => ['required_with:availability', 'date_format:H:i', 'after:availability.*.start_time'],
            'availability.*.slot_duration_minutes' => ['required_with:availability', 'integer', 'min:5', 'max:60'],
        ]);
    }

    /**
     * @param  array<int, array{day_of_week:int,start_time:string,end_time:string,slot_duration_minutes:int}>  $slots
     */
    private function replaceAvailability(Doctor $doctor, array $slots): void
    {
        $doctor->availability()->delete();

        foreach ($slots as $slot) {
            $doctor->availability()->create([
                'day_of_week' => $slot['day_of_week'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'slot_duration_minutes' => $slot['slot_duration_minutes'],
            ]);
        }
    }

    private function storePhoto(Request $request): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        return $request->file('photo')->store('doctor-photos', 'public');
    }

    private function normalizeTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function normalizeMultipartPayload(Request $request): void
    {
        $availability = $request->input('availability');
        if (is_string($availability)) {
            $decoded = json_decode($availability, true);
            $request->merge(['availability' => is_array($decoded) ? $decoded : []]);
        }

        if ($request->has('must_reset_password')) {
            $request->merge([
                'must_reset_password' => filter_var($request->input('must_reset_password'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        foreach (['clinic_id', 'department_id', 'consultation_fee'] as $nullable) {
            if ($request->input($nullable) === '' || $request->input($nullable) === 'null') {
                $request->merge([$nullable => null]);
            }
        }
    }
}
