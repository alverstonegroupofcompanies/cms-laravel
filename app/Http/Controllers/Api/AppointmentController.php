<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\DoctorStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
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
        ]);

        return response()->json([
            'slots' => $slotService->getAvailableSlots((int) $data['doctor_id'], $data['date']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $appointments = Appointment::with(['patient', 'doctor'])
            ->when($request->doctor_id, fn ($q, $id) => $q->where('doctor_id', $id))
            ->when($request->patient_id, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($request->date, fn ($q, $date) => $q->whereDate('appointment_date', $date))
            ->latest()
            ->paginate(20);

        return response()->json($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'doctor_id' => ['required', 'exists:doctors,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'slot_time' => ['required', 'date_format:H:i:s'],
        ]);

        $exists = Appointment::where('doctor_id', $data['doctor_id'])
            ->whereDate('appointment_date', $data['appointment_date'])
            ->where('slot_time', $data['slot_time'])
            ->whereNotIn('status', [AppointmentStatus::Cancelled])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'This slot is already booked.'], 422);
        }

        $appointment = Appointment::create([
            ...$data,
            'status' => AppointmentStatus::Booked,
            'type' => 'scheduled',
        ]);

        return response()->json($appointment->load(['patient', 'doctor']), 201);
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
}
