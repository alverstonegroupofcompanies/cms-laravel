<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Services\PatientCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'unique:patients,phone'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'dob' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string'],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'unknown'])],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $fullName = trim($data['first_name'].' '.($data['last_name'] ?? ''));

        $patient = Patient::create([
            'patient_code' => $codeGenerator->generate(),
            'registration_source' => 'walk_in',
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
            'photo_path' => $this->storePhoto($request),
        ]);

        return response()->json($patient, 201);
    }

    public function issueLogin(Patient $patient): JsonResponse
    {
        if ($patient->user_id) {
            return response()->json(['message' => 'Patient already has login access.'], 422);
        }

        $tempPassword = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $email = $patient->email ?: $patient->phone.'@patient.clinic.local';

        $user = \App\Models\User::create([
            'name' => $patient->name,
            'email' => $email,
            'phone' => $patient->phone,
            'password' => $tempPassword,
            'role' => \App\Enums\UserRole::Patient,
            'status' => 'active',
            'must_reset_password' => true,
        ]);

        $patient->update(['user_id' => $user->id]);

        return response()->json([
            'message' => 'Login credentials created. Patient must reset password on first login.',
            'patient' => $patient->fresh(),
            'login_email' => $email,
            'temp_password' => $tempPassword,
        ]);
    }

    public function show(Patient $patient): JsonResponse
    {
        return response()->json($patient->load(['appointments.doctor', 'queueTokens.doctor']));
    }

    public function me(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            return response()->json(['message' => 'Patient profile not found.'], 404);
        }

        return response()->json($patient);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $patient = $user->patient;

        if (! $patient) {
            return response()->json(['message' => 'Patient profile not found.'], 404);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'dob' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'phone' => ['required', 'string', 'max:20', Rule::unique('patients', 'phone')->ignore($patient->id), Rule::unique('users', 'phone')->ignore($user->id)],
            'address' => ['nullable', 'string'],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'unknown'])],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed', 'prefer_not_to_say'])],
            'symptoms' => ['nullable', 'string'],
            'taking_medications' => ['nullable', 'boolean'],
            'current_medications' => ['nullable', 'string'],
            'emergency_contact_first_name' => ['nullable', 'string', 'max:100'],
            'emergency_contact_last_name' => ['nullable', 'string', 'max:100'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $fullName = trim($data['first_name'].' '.($data['last_name'] ?? ''));
        $fullAddress = collect([
            $data['address_street'] ?? null,
            $data['address_line2'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['postal_code'] ?? null,
        ])->filter()->implode(', ');

        $photoPath = $this->storePhoto($request) ?? $patient->photo_path;

        $patient->update([
            ...$data,
            'name' => $fullName,
            'last_name' => $data['last_name'] ?? null,
            'address' => $fullAddress ?: ($data['address'] ?? $patient->address),
            'photo_path' => $photoPath,
        ]);

        $user->update([
            'name' => $fullName,
            'phone' => $data['phone'],
        ]);

        return response()->json($user->fresh()->load('patient'));
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('phone', $request->input('q', '')));

        $patients = Patient::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('phone', 'like', "%{$q}%")
                        ->orWhere('patient_code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->latest()
            ->limit(50)
            ->get();

        return response()->json($this->withVisitSummaries($patients));
    }

    /**
     * @param  Collection<int, Patient>  $patients
     * @return Collection<int, Patient>
     */
    private function withVisitSummaries(Collection $patients): Collection
    {
        if ($patients->isEmpty()) {
            return $patients;
        }

        $ids = $patients->pluck('id');
        $today = now()->toDateString();

        $byPatient = Appointment::query()
            ->whereIn('patient_id', $ids)
            ->with('doctor:id,name,specialization')
            ->orderBy('appointment_date')
            ->orderBy('slot_time')
            ->get()
            ->groupBy('patient_id');

        $active = [
            AppointmentStatus::Booked->value,
            AppointmentStatus::CheckedIn->value,
        ];

        foreach ($patients as $patient) {
            $appts = $byPatient->get($patient->id, collect());

            $upcoming = $appts->first(function (Appointment $a) use ($today, $active) {
                $status = $a->status instanceof AppointmentStatus ? $a->status->value : (string) $a->status;

                return in_array($status, $active, true)
                    && $a->appointment_date->toDateString() >= $today;
            });

            $last = $appts
                ->sortByDesc(fn (Appointment $a) => $a->appointment_date->format('Y-m-d').' '.($a->slot_time ?? '00:00:00'))
                ->first(function (Appointment $a) use ($today, $upcoming) {
                    if ($upcoming && $a->id === $upcoming->id) {
                        return false;
                    }
                    $status = $a->status instanceof AppointmentStatus ? $a->status->value : (string) $a->status;
                    if (in_array($status, [AppointmentStatus::Completed->value, AppointmentStatus::NoShow->value, AppointmentStatus::Cancelled->value], true)) {
                        return true;
                    }

                    return $a->appointment_date->toDateString() < $today;
                });

            $patient->setAttribute('upcoming_appointment', $upcoming ? $this->summarizeAppointment($upcoming) : null);
            $patient->setAttribute('last_appointment', $last ? $this->summarizeAppointment($last) : null);
        }

        return $patients;
    }

    private function summarizeAppointment(Appointment $appointment): array
    {
        $status = $appointment->status instanceof AppointmentStatus
            ? $appointment->status->value
            : (string) $appointment->status;

        return [
            'id' => $appointment->id,
            'appointment_date' => $appointment->appointment_date->toDateString(),
            'slot_time' => $appointment->slot_time ? substr((string) $appointment->slot_time, 0, 5) : null,
            'status' => $status,
            'doctor' => $appointment->doctor ? [
                'id' => $appointment->doctor->id,
                'name' => $appointment->doctor->name,
                'specialization' => $appointment->doctor->specialization,
            ] : null,
        ];
    }

    private function storePhoto(Request $request): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        return $request->file('photo')->store('patient-photos', 'public');
    }
}
