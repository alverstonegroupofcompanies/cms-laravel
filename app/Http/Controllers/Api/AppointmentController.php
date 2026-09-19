<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Services\QueueService;
use App\Services\SlotGeneratorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function slots(Request $request, SlotGeneratorService $slotService): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'exclude_appointment_id' => ['sometimes', 'integer', 'exists:appointments,id'],
        ]);

        return response()->json([
            'slots' => $slotService->getAvailableSlots(
                (int) $data['doctor_id'],
                $data['date'],
                isset($data['exclude_appointment_id']) ? (int) $data['exclude_appointment_id'] : null
            ),
        ]);
    }

    public function daySchedule(Request $request, SlotGeneratorService $slotService): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'date' => ['required', 'date'],
        ]);

        $doctor = Doctor::with(['department', 'clinic', 'availability'])->findOrFail($data['doctor_id']);

        return response()->json([
            'doctor' => $doctor,
            'date' => $data['date'],
            'slots' => $slotService->getDaySchedule((int) $data['doctor_id'], $data['date']),
        ]);
    }

    /**
     * Month calendar: patient counts per day for staff / doctor dashboards.
     */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
        ]);

        $user = $request->user();
        $doctorId = isset($data['doctor_id']) ? (int) $data['doctor_id'] : null;

        if ($user?->role === UserRole::Patient) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($user?->role === UserRole::Doctor) {
            $doctorId = $user->doctor?->id
                ?? Doctor::where('user_id', $user->id)->value('id');
            if (! $doctorId) {
                return response()->json(['year' => $data['year'], 'month' => $data['month'], 'days' => []]);
            }
        }

        $start = sprintf('%04d-%02d-01', $data['year'], $data['month']);
        $end = date('Y-m-t', strtotime($start));

        $rows = Appointment::query()
            ->selectRaw('appointment_date as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as booked")
            ->selectRaw("SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) as checked_in")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show")
            ->selectRaw("SUM(CASE WHEN type = 'walk_in' THEN 1 ELSE 0 END) as walk_in")
            ->whereBetween('appointment_date', [$start, $end])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->groupBy('appointment_date')
            ->orderBy('appointment_date')
            ->get();

        $days = $rows->map(fn ($r) => [
            'date' => $r->day instanceof \DateTimeInterface
                ? $r->day->format('Y-m-d')
                : substr((string) $r->day, 0, 10),
            'total' => (int) $r->total,
            'booked' => (int) $r->booked,
            'checked_in' => (int) $r->checked_in,
            'completed' => (int) $r->completed,
            'no_show' => (int) $r->no_show,
            'walk_in' => (int) $r->walk_in,
        ])->values();

        return response()->json([
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'doctor_id' => $doctorId,
            'days' => $days,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isPatient = $user?->role === UserRole::Patient;

        $query = Appointment::with(['patient', 'doctor', 'clinic', 'department', 'queueToken']);

        if ($isPatient) {
            $patientId = $user->patient?->id
                ?? \App\Models\Patient::where('user_id', $user->id)->value('id');
            if (! $patientId) {
                return response()->json(['data' => [], 'total' => 0]);
            }

            // Patient portal: own visits only, include staff status changes (cancel / check-in / complete).
            $query->where('patient_id', $patientId)
                ->whereDate('appointment_date', '>=', now()->subDays(180)->toDateString());
        } else {
            $query->when($request->doctor_id, fn ($q, $id) => $q->where('doctor_id', $id))
                ->when($request->patient_id, fn ($q, $id) => $q->where('patient_id', $id))
                ->when(
                    $request->filled('date'),
                    fn ($q) => $q->whereDate('appointment_date', $request->date),
                    fn ($q) => $q->whereDate('appointment_date', '>=', now()->toDateString())
                );

            if (! $request->boolean('include_cancelled')) {
                $query->whereNotIn('status', [AppointmentStatus::Cancelled]);
            }
        }

        $appointments = $query
            ->orderBy('appointment_date')
            ->orderBy('slot_time')
            ->paginate(100);

        return response()->json($appointments);
    }

    public function store(Request $request, SlotGeneratorService $slotService): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'doctor_id' => ['required', 'exists:doctors,id'],
            'clinic_id' => ['nullable', 'exists:clinics,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'slot_time' => ['required', 'date_format:H:i:s'],
            'type' => ['nullable', 'in:scheduled,walk_in'],
        ]);

        $doctor = Doctor::findOrFail($data['doctor_id']);

        if (! $slotService->doctorWorksOnDate((int) $data['doctor_id'], $data['appointment_date'])) {
            return response()->json(['message' => 'Doctor not available on this date.'], 422);
        }

        $openSlots = $slotService->getAvailableSlots((int) $data['doctor_id'], $data['appointment_date']);
        $slotOk = collect($openSlots)->contains(fn (array $s) => ($s['slot_time'] ?? null) === $data['slot_time']);
        if (! $slotOk) {
            return response()->json(['message' => 'This slot is not available.'], 422);
        }

        $exists = Appointment::where('doctor_id', $data['doctor_id'])
            ->whereDate('appointment_date', $data['appointment_date'])
            ->where('slot_time', $data['slot_time'])
            ->whereNotIn('status', [AppointmentStatus::Cancelled])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'This slot is already booked.'], 422);
        }

        $appointment = Appointment::create([
            'patient_id' => $data['patient_id'],
            'doctor_id' => $data['doctor_id'],
            'clinic_id' => $data['clinic_id'] ?? $doctor->clinic_id,
            'department_id' => $data['department_id'] ?? $doctor->department_id,
            'appointment_date' => $data['appointment_date'],
            'slot_time' => $data['slot_time'],
            'status' => AppointmentStatus::Booked,
            'type' => $data['type'] ?? 'scheduled',
        ]);

        $token = null;
        $appointmentDate = $appointment->appointment_date->toDateString();
        $isToday = $appointment->appointment_date->isToday();

        // Always issue the day's token at booking so the patient can see it immediately.
        // Same-day bookings also join the live queue (checked in); future days keep status booked.
        $token = app(QueueService::class)->joinQueue(
            $appointment->patient_id,
            $appointment->doctor_id,
            $appointmentDate,
            $appointment->id,
            false,
            $isToday
        );

        return response()->json([
            'appointment' => $appointment->fresh()->load(['patient', 'doctor', 'clinic', 'department', 'queueToken']),
            'queue_token' => $token,
        ], 201);
    }

    public function checkIn(Appointment $appointment, QueueService $queueService): JsonResponse
    {
        try {
            $token = $queueService->checkInAppointment($appointment);

            return response()->json([
                'appointment' => $appointment->fresh()->load(['patient', 'doctor']),
                'queue_token' => $token,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function checkOut(Request $request, Appointment $appointment, QueueService $queueService): JsonResponse
    {
        $user = $request->user();
        $token = \App\Models\QueueToken::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', [
                \App\Enums\QueueTokenStatus::Waiting,
                \App\Enums\QueueTokenStatus::InConsultation,
            ])
            ->latest('id')
            ->first();

        // Ending an active consult is doctor-only.
        if ($token && $token->status === \App\Enums\QueueTokenStatus::InConsultation) {
            $isAdmin = $user?->hasRole(UserRole::Admin, 'admin');
            $isTreatingDoctor = ($user?->role === UserRole::Doctor || $user?->role === 'doctor')
                && (int) $user->doctor?->id === (int) $token->doctor_id;

            if (! $isAdmin && ! $isTreatingDoctor) {
                return response()->json([
                    'message' => 'Only the treating doctor can check out after consultation.',
                ], 403);
            }
        }

        if ($token) {
            $completed = $queueService->complete($token->id);

            return response()->json([
                'appointment' => $appointment->fresh()->load(['patient', 'doctor']),
                'queue_token' => $completed,
            ]);
        }

        if ($appointment->status === AppointmentStatus::CheckedIn) {
            $appointment->update(['status' => AppointmentStatus::Completed]);

            return response()->json([
                'appointment' => $appointment->fresh()->load(['patient', 'doctor']),
                'queue_token' => null,
            ]);
        }

        return response()->json([
            'message' => 'Patient must be checked in (or in consultation) before checkout.',
        ], 422);
    }

    public function cancel(Appointment $appointment): JsonResponse
    {
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        \App\Models\QueueToken::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', [
                \App\Enums\QueueTokenStatus::Waiting,
                \App\Enums\QueueTokenStatus::InConsultation,
            ])
            ->update(['status' => \App\Enums\QueueTokenStatus::Cancelled]);

        return response()->json($appointment->fresh());
    }

    public function reschedule(Request $request, Appointment $appointment, SlotGeneratorService $slotService): JsonResponse
    {
        // Same rules for staff and patient portal
        $reschedulable = [
            AppointmentStatus::Booked,
            AppointmentStatus::NoShow,
            AppointmentStatus::CheckedIn,
        ];

        if (! in_array($appointment->status, $reschedulable, true)) {
            return response()->json(['message' => 'Only booked, checked-in, or missed appointments can be rescheduled.'], 422);
        }

        $data = $request->validate([
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'slot_time' => ['required', 'date_format:H:i:s'],
        ]);

        $slots = $slotService->getAvailableSlots(
            $appointment->doctor_id,
            $data['appointment_date'],
            $appointment->id
        );

        $slotAvailable = collect($slots)->contains(fn ($s) => $s['slot_time'] === $data['slot_time']);

        if (! $slotAvailable) {
            return response()->json(['message' => 'This slot is not available.'], 422);
        }

        $oldDate = $appointment->appointment_date->toDateString();
        $newDate = Carbon::parse($data['appointment_date'])->toDateString();

        $existingToken = \App\Models\QueueToken::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', [
                \App\Enums\QueueTokenStatus::Waiting,
                \App\Enums\QueueTokenStatus::InConsultation,
            ])
            ->latest('id')
            ->first();

        $appointment->update([
            'appointment_date' => $data['appointment_date'],
            'slot_time' => $data['slot_time'],
            'status' => AppointmentStatus::Booked,
            'reminder_sent_at' => null,
            'missed_notified_at' => null,
        ]);

        $isToday = Carbon::parse($newDate)->isToday();
        $token = null;

        // Same-day reschedule: keep the existing token number (only the slot time changes).
        // Token number changes only when the visit moves to another day (or there was no token).
        if ($newDate === $oldDate && $existingToken) {
            if ($existingToken->status === \App\Enums\QueueTokenStatus::InConsultation) {
                $existingToken->update([
                    'status' => \App\Enums\QueueTokenStatus::Waiting,
                    'called_at' => null,
                ]);
            }
            if ($isToday) {
                $appointment->update(['status' => AppointmentStatus::CheckedIn]);
            }
            $token = $existingToken->fresh()->load(['patient', 'doctor', 'appointment']);
        } else {
            if ($existingToken) {
                $existingToken->update(['status' => \App\Enums\QueueTokenStatus::Cancelled]);
            }
            $token = app(QueueService::class)->joinQueue(
                $appointment->patient_id,
                $appointment->doctor_id,
                $newDate,
                $appointment->id,
                false,
                $isToday
            );
        }

        return response()->json([
            'appointment' => $appointment->fresh()->load(['patient', 'doctor', 'clinic', 'department', 'queueToken']),
            'queue_token' => $token,
        ]);
    }
}
