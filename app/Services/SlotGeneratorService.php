<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\DoctorAvailability;
use Carbon\Carbon;

class SlotGeneratorService
{
    public function getAvailableSlots(int $doctorId, string $date): array
    {
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
        $duration = $availability->slot_duration_minutes;

        while ($start->copy()->addMinutes($duration)->lte($end)) {
            $slotTime = $start->format('H:i:s');
            $isBooked = Appointment::where('doctor_id', $doctorId)
                ->whereDate('appointment_date', $date)
                ->where('slot_time', $slotTime)
                ->whereNotIn('status', [AppointmentStatus::Cancelled])
                ->exists();

            if (! $isBooked && $start->gt(now())) {
                $slots[] = [
                    'time' => $start->format('H:i'),
                    'slot_time' => $slotTime,
                ];
            }

            $start->addMinutes($duration);
        }

        return $slots;
    }
}
