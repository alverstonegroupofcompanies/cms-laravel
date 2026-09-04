<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorAvailability;
use App\Models\DoctorLeave;
use Carbon\Carbon;

class SlotGeneratorService
{
    public function getAvailableSlots(int $doctorId, string $date, ?int $excludeAppointmentId = null): array
    {
        return array_values(array_filter(
            $this->getDaySchedule($doctorId, $date, $excludeAppointmentId),
            fn (array $slot) => ($slot['status'] ?? '') === 'available'
        ));
    }

    /**
     * Full day board: available, booked (with patient), lunch, past.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDaySchedule(int $doctorId, string $date, ?int $excludeAppointmentId = null): array
    {
        if (DoctorLeave::where('doctor_id', $doctorId)->whereDate('leave_date', $date)->exists()) {
            return [];
        }

        $doctor = Doctor::find($doctorId);
        $dayOfWeek = Carbon::parse($date)->dayOfWeek;
        $availability = DoctorAvailability::where('doctor_id', $doctorId)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $availability) {
            return [];
        }

        $slots = [];
        $start = Carbon::parse($date.' '.$availability->start_time);
        $end = Carbon::parse($date.' '.$availability->end_time);
        $duration = $availability->slot_duration_minutes ?: 30;
        $now = Carbon::now();

        $lunchStart = $doctor?->lunch_start ? Carbon::parse($date.' '.$doctor->lunch_start) : null;
        $lunchEnd = $doctor?->lunch_end ? Carbon::parse($date.' '.$doctor->lunch_end) : null;

        $bookings = Appointment::with('patient')
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', [AppointmentStatus::Cancelled])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->get()
            ->keyBy(fn (Appointment $a) => Carbon::parse($a->slot_time)->format('H:i:s'));

        while ($start->copy()->addMinutes($duration)->lte($end)) {
            $slotEnd = $start->copy()->addMinutes($duration);
            $slotTime = $start->format('H:i:s');
            $inLunch = $lunchStart && $lunchEnd
                && $start->lt($lunchEnd) && $slotEnd->gt($lunchStart);

            $booking = $bookings->get($slotTime);
            $isPast = $start->lte($now);

            if ($inLunch) {
                $status = 'lunch';
            } elseif ($booking) {
                $status = 'booked';
            } elseif ($isPast) {
                $status = 'past';
            } else {
                $status = 'available';
            }

            $entry = [
                'time' => $start->format('H:i'),
                'slot_time' => $slotTime,
                'duration_minutes' => $duration,
                'status' => $status,
            ];

            if ($booking) {
                $entry['appointment_id'] = $booking->id;
                $entry['appointment_status'] = $booking->status?->value ?? $booking->status;
                $entry['appointment_type'] = $booking->type;
                $entry['patient'] = $booking->patient ? [
                    'id' => $booking->patient->id,
                    'name' => $booking->patient->name,
                    'patient_code' => $booking->patient->patient_code,
                    'phone' => $booking->patient->phone,
                    'email' => $booking->patient->email,
                ] : null;
            }

            $slots[] = $entry;
            $start->addMinutes($duration);
        }

        return $slots;
    }

    /**
     * @return array<int, array{date: string, day: string, label: string}>
     */
    public function getAvailableDates(int $doctorId, int $days = 28): array
    {
        $availabilityDays = DoctorAvailability::where('doctor_id', $doctorId)
            ->pluck('day_of_week')
            ->all();

        if (empty($availabilityDays)) {
            return [];
        }

        $from = Carbon::today();
        $to = $from->copy()->addDays($days - 1);

        $leaveDates = DoctorLeave::where('doctor_id', $doctorId)
            ->whereBetween('leave_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('leave_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->all();

        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i);
            $dateStr = $date->format('Y-m-d');

            if (in_array($date->dayOfWeek, $availabilityDays, true) && ! in_array($dateStr, $leaveDates, true)) {
                $dates[] = [
                    'date' => $dateStr,
                    'day' => $date->format('D'),
                    'label' => $date->format('D, M j'),
                ];
            }
        }

        return $dates;
    }
}
