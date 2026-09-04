<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Services\QueueService;
use App\Services\SlotGeneratorService;
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

    public function index(Request $request): JsonResponse
    {
        $appointments = Appointment::with(['patient', 'doctor'])
            ->when($request->doctor_id, fn ($q, $id) => $q->where('doctor_id', $id))
            ->when($request->patient_id, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($request->date, fn ($q, $date) => $q->whereDate('appointment_date', $date))
            ->orderBy('appointment_date')
            ->orderBy('slot_time')
            ->paginate(50);

        return response()->json($appointments);
    }

    public function store(Request $request): JsonResponse
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
        $isToday = $appointment->appointment_date->toDateString() === now()->toDateString();

        if ($isToday) {
            $token = app(QueueService::class)->joinQueue(
                $appointment->patient_id,
                $appointment->doctor_id,
                $appointment->appointment_date->toDateString(),
                $appointment->id
            );
        }

        return response()->json([
            'appointment' => $appointment->load(['patient', 'doctor', 'clinic', 'department']),
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

    public function cancel(Appointment $appointment): JsonResponse
    {
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        return response()->json($appointment);
    }

    public function reschedule(Request $request, Appointment $appointment, SlotGeneratorService $slotService): JsonResponse
    {
        $reschedulable = [AppointmentStatus::Booked, AppointmentStatus::NoShow];

        if (! in_array($appointment->status, $reschedulable, true)) {
            return response()->json(['message' => 'Only booked or missed appointments can be rescheduled.'], 422);
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

        $appointment->update([
            'appointment_date' => $data['appointment_date'],
            'slot_time' => $data['slot_time'],
            'status' => AppointmentStatus::Booked,
        ]);

        $appointment->load('queueToken');

        if ($appointment->queueToken) {
            $appointment->queueToken->update(['queue_date' => $data['appointment_date']]);
        }

        return response()->json($appointment->fresh()->load(['patient', 'doctor', 'clinic', 'department']));
    }
}
