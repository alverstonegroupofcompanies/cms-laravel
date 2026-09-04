<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentMissedMail;
use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AppointmentsSendNotifications extends Command
{
    protected $signature = 'appointments:send-notifications';

    protected $description = 'Email patients 2 hours before appointments and when a booked slot is missed';

    public function handle(): int
    {
        $reminders = $this->sendReminders();
        $missed = $this->markMissedAndNotify();

        $this->info("Reminders sent: {$reminders}. Missed notifications: {$missed}.");

        return self::SUCCESS;
    }

    private function sendReminders(): int
    {
        $now = Carbon::now();
        // Enter the 2-hour window: slot between now+1h55m and now+2h5m roughly,
        // but also catch anything already inside the 2h window that was never reminded.
        $windowStart = $now->copy();
        $windowEnd = $now->copy()->addHours(2);

        $sent = 0;

        Appointment::query()
            ->with(['patient.user', 'doctor', 'clinic'])
            ->where('status', AppointmentStatus::Booked)
            ->whereNull('reminder_sent_at')
            ->whereDate('appointment_date', '>=', $now->toDateString())
            ->whereDate('appointment_date', '<=', $windowEnd->toDateString())
            ->orderBy('id')
            ->chunkById(50, function ($appointments) use ($windowStart, $windowEnd, &$sent) {
                foreach ($appointments as $appointment) {
                    $start = $this->appointmentDateTime($appointment);

                    if ($start->lt($windowStart) || $start->gt($windowEnd)) {
                        continue;
                    }

                    $email = $this->patientEmail($appointment);
                    if (! $email) {
                        continue;
                    }

                    try {
                        Mail::to($email)->send(new AppointmentReminderMail($appointment));
                        $appointment->update(['reminder_sent_at' => now()]);
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::error('Appointment reminder failed', [
                            'appointment_id' => $appointment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $sent;
    }

    private function markMissedAndNotify(): int
    {
        $now = Carbon::now();
        $sent = 0;

        Appointment::query()
            ->with(['patient.user', 'doctor', 'clinic'])
            ->where('status', AppointmentStatus::Booked)
            ->whereNull('missed_notified_at')
            ->where(function ($q) use ($now) {
                $q->whereDate('appointment_date', '<', $now->toDateString())
                    ->orWhere(function ($q2) use ($now) {
                        $q2->whereDate('appointment_date', $now->toDateString())
                            ->where('slot_time', '<=', $now->format('H:i:s'));
                    });
            })
            ->orderBy('id')
            ->chunkById(50, function ($appointments) use ($now, &$sent) {
                foreach ($appointments as $appointment) {
                    $start = $this->appointmentDateTime($appointment);
                    if ($start->gte($now)) {
                        continue;
                    }

                    $appointment->update([
                        'status' => AppointmentStatus::NoShow,
                        'missed_notified_at' => now(),
                    ]);

                    $email = $this->patientEmail($appointment);
                    if (! $email) {
                        continue;
                    }

                    try {
                        Mail::to($email)->send(new AppointmentMissedMail($appointment));
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::error('Appointment missed mail failed', [
                            'appointment_id' => $appointment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $sent;
    }

    private function appointmentDateTime(Appointment $appointment): Carbon
    {
        $date = $appointment->appointment_date->format('Y-m-d');
        $time = substr((string) $appointment->slot_time, 0, 8);
        if (strlen($time) === 5) {
            $time .= ':00';
        }

        return Carbon::parse("{$date} {$time}", config('app.timezone'));
    }

    private function patientEmail(Appointment $appointment): ?string
    {
        $email = $appointment->patient?->email
            ?: $appointment->patient?->user?->email;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Skip placeholder emails created for walk-in logins
        if (str_ends_with(strtolower($email), '@patient.clinic.local')) {
            return null;
        }

        return $email;
    }
}
