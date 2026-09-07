<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\QueueTokenStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorAvailability;
use App\Models\DoctorLeave;
use App\Models\QueueToken;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
     * Full day board: available, booked (with patient + queue timing), lunch, past.
     * Includes walk-ins and any visits that fall outside the generated slot grid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDaySchedule(int $doctorId, string $date, ?int $excludeAppointmentId = null): array
    {
        $onLeave = DoctorLeave::where('doctor_id', $doctorId)->whereDate('leave_date', $date)->exists();
        $doctor = Doctor::find($doctorId);
        $dayOfWeek = Carbon::parse($date)->dayOfWeek;
        $availability = DoctorAvailability::where('doctor_id', $doctorId)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        $bookings = Appointment::with(['patient', 'queueToken'])
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', [AppointmentStatus::Cancelled])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->orderBy('slot_time')
            ->get();

        $orphanTokens = QueueToken::with('patient')
            ->where('doctor_id', $doctorId)
            ->whereDate('queue_date', $date)
            ->whereNull('appointment_id')
            ->whereNotIn('status', [QueueTokenStatus::Cancelled])
            ->orderBy('token_number')
            ->get();

        if ($onLeave || ! $availability) {
            return $this->visitRowsOnly($bookings, $orphanTokens);
        }

        $slots = [];
        $start = Carbon::parse($date.' '.$availability->start_time);
        $end = Carbon::parse($date.' '.$availability->end_time);
        $duration = $availability->slot_duration_minutes ?: 30;
        $now = Carbon::now();

        $lunchStart = $doctor?->lunch_start ? Carbon::parse($date.' '.$doctor->lunch_start) : null;
        $lunchEnd = $doctor?->lunch_end ? Carbon::parse($date.' '.$doctor->lunch_end) : null;

        /** @var Collection<string, Collection<int, Appointment>> $bySlot */
        $bySlot = $bookings->groupBy(fn (Appointment $a) => Carbon::parse($a->slot_time)->format('H:i:s'));
        $matchedAppointmentIds = [];

        while ($start->copy()->addMinutes($duration)->lte($end)) {
            $slotEnd = $start->copy()->addMinutes($duration);
            $slotTime = $start->format('H:i:s');
            $inLunch = $lunchStart && $lunchEnd
                && $start->lt($lunchEnd) && $slotEnd->gt($lunchStart);

            $slotBookings = $bySlot->get($slotTime, collect());
            $isPast = $start->lte($now);

            if ($inLunch && $slotBookings->isEmpty()) {
                $slots[] = [
                    'time' => $start->format('H:i'),
                    'slot_time' => $slotTime,
                    'duration_minutes' => $duration,
                    'status' => 'lunch',
                ];
            } elseif ($slotBookings->isNotEmpty()) {
                foreach ($slotBookings as $index => $booking) {
                    $matchedAppointmentIds[] = $booking->id;
                    $entry = $this->appointmentSlotEntry($booking, $duration, $index === 0 ? 'booked' : 'booked');
                    // Keep first booking on the grid time; extras still use the same slot_time.
                    $slots[] = $entry;
                }
            } elseif ($isPast) {
                $slots[] = [
                    'time' => $start->format('H:i'),
                    'slot_time' => $slotTime,
                    'duration_minutes' => $duration,
                    'status' => 'past',
                ];
            } else {
                $slots[] = [
                    'time' => $start->format('H:i'),
                    'slot_time' => $slotTime,
                    'duration_minutes' => $duration,
                    'status' => 'available',
                ];
            }

            $start->addMinutes($duration);
        }

        foreach ($bookings as $booking) {
            if (in_array($booking->id, $matchedAppointmentIds, true)) {
                continue;
            }
            $slots[] = $this->appointmentSlotEntry($booking, $duration, 'booked');
        }

        foreach ($orphanTokens as $token) {
            $slots[] = $this->tokenSlotEntry($token);
        }

        return $slots;
    }

    /**
     * @param  Collection<int, Appointment>  $bookings
     * @param  Collection<int, QueueToken>  $orphanTokens
     * @return array<int, array<string, mixed>>
     */
    private function visitRowsOnly(Collection $bookings, Collection $orphanTokens): array
    {
        $slots = [];
        foreach ($bookings as $booking) {
            $slots[] = $this->appointmentSlotEntry($booking, null, 'booked');
        }
        foreach ($orphanTokens as $token) {
            $slots[] = $this->tokenSlotEntry($token);
        }

        usort($slots, fn ($a, $b) => strcmp($a['slot_time'] ?? '', $b['slot_time'] ?? ''));

        return $slots;
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentSlotEntry(Appointment $booking, ?int $duration, string $status): array
    {
        $slotTime = $booking->slot_time
            ? Carbon::parse($booking->slot_time)->format('H:i:s')
            : '00:00:00';
        $token = $booking->queueToken;

        return array_merge([
            'time' => substr($slotTime, 0, 5),
            'slot_time' => $slotTime,
            'duration_minutes' => $duration,
            'status' => $status,
            'appointment_id' => $booking->id,
            'appointment_status' => $booking->status?->value ?? $booking->status,
            'appointment_type' => $booking->type ?: 'scheduled',
            'source' => ($booking->type === 'walk_in') ? 'walk_in' : 'online',
            'patient' => $booking->patient ? [
                'id' => $booking->patient->id,
                'name' => $booking->patient->name,
                'patient_code' => $booking->patient->patient_code,
                'phone' => $booking->patient->phone,
                'email' => $booking->patient->email,
            ] : null,
        ], $this->queuePayload($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenSlotEntry(QueueToken $token): array
    {
        $created = $token->created_at ? Carbon::parse($token->created_at) : null;
        $slotTime = $created ? $created->format('H:i:s') : '00:00:00';

        return array_merge([
            'time' => substr($slotTime, 0, 5),
            'slot_time' => $slotTime,
            'duration_minutes' => null,
            'status' => 'booked',
            'appointment_id' => null,
            'appointment_status' => null,
            'appointment_type' => 'walk_in',
            'source' => 'walk_in',
            'patient' => $token->patient ? [
                'id' => $token->patient->id,
                'name' => $token->patient->name,
                'patient_code' => $token->patient->patient_code,
                'phone' => $token->patient->phone,
                'email' => $token->patient->email,
            ] : null,
        ], $this->queuePayload($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function queuePayload(?QueueToken $token): array
    {
        if (! $token || $token->status === QueueTokenStatus::Cancelled) {
            return [
                'token_id' => null,
                'token_number' => null,
                'display_code' => null,
                'queue_status' => null,
                'checked_in_at' => null,
                'checked_out_at' => null,
                'entered_at' => null,
            ];
        }

        return [
            'token_id' => $token->id,
            'token_number' => $token->token_number,
            'display_code' => $token->display_code,
            'queue_status' => $token->status?->value ?? $token->status,
            'checked_in_at' => $token->called_at?->format('H:i') ?? null,
            'checked_out_at' => $token->completed_at?->format('H:i') ?? null,
            'entered_at' => $token->created_at?->format('H:i') ?? null,
        ];
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
