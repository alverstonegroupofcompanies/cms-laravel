<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\QueueTokenStatus;
use App\Events\QueueUpdated;
use App\Models\Appointment;
use App\Models\QueueToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QueueService
{
    public function joinQueue(int $patientId, int $doctorId, ?string $date = null, ?int $appointmentId = null): QueueToken
    {
        $queueDate = $date ?? now()->toDateString();

        $existing = QueueToken::where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->whereDate('queue_date', $queueDate)
            ->whereIn('status', [QueueTokenStatus::Waiting, QueueTokenStatus::InConsultation])
            ->first();

        if ($existing) {
            return $existing->load(['patient', 'doctor']);
        }

        return DB::transaction(function () use ($patientId, $doctorId, $queueDate, $appointmentId) {
            $lastToken = QueueToken::where('doctor_id', $doctorId)
                ->whereDate('queue_date', $queueDate)
                ->lockForUpdate()
                ->orderByDesc('token_number')
                ->first();

            $tokenNumber = ($lastToken?->token_number ?? 0) + 1;

            $token = QueueToken::create([
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'queue_date' => $queueDate,
                'token_number' => $tokenNumber,
                'display_code' => 'Q-'.str_pad((string) $tokenNumber, 3, '0', STR_PAD_LEFT),
                'status' => QueueTokenStatus::Waiting,
                'appointment_id' => $appointmentId,
            ]);

            event(new QueueUpdated($doctorId, $queueDate));

            return $token->load(['patient', 'doctor']);
        });
    }

    public function checkInAppointment(Appointment $appointment): QueueToken
    {
        if ($appointment->status !== AppointmentStatus::Booked) {
            throw new InvalidArgumentException('Only booked appointments can be checked in.');
        }

        $appointment->update(['status' => AppointmentStatus::CheckedIn]);

        return $this->joinQueue(
            $appointment->patient_id,
            $appointment->doctor_id,
            $appointment->appointment_date->toDateString(),
            $appointment->id
        );
    }

    public function getQueueStatus(int $doctorId, string $date): array
    {
        $tokens = $this->getOrderedQueue($doctorId, $date);

        return $tokens->map(function (QueueToken $token, int $index) {
            return [
                'id' => $token->id,
                'token_number' => $token->token_number,
                'display_code' => $token->display_code,
                'status' => $token->status->value,
                'position' => $index + 1,
                'patient' => [
                    'id' => $token->patient->id,
                    'name' => $token->patient->name,
                    'patient_code' => $token->patient->patient_code,
                ],
                'appointment_id' => $token->appointment_id,
                'called_at' => $token->called_at,
            ];
        })->values()->all();
    }

    public function getMyPosition(int $patientId, int $doctorId, string $date): ?array
    {
        $token = QueueToken::where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->whereDate('queue_date', $date)
            ->whereIn('status', [QueueTokenStatus::Waiting, QueueTokenStatus::InConsultation])
            ->first();

        if (! $token) {
            return null;
        }

        $queue = $this->getOrderedQueue($doctorId, $date)
            ->filter(fn (QueueToken $t) => in_array($t->status, [QueueTokenStatus::Waiting, QueueTokenStatus::InConsultation]));

        $position = $queue->search(fn (QueueToken $t) => $t->id === $token->id);

        return [
            'token' => $token->load('doctor'),
            'position' => $position !== false ? $position + 1 : null,
            'estimated_wait_minutes' => $position !== false ? $position * 10 : 0,
            'queue_length' => $queue->count(),
        ];
    }

    public function callNext(int $doctorId, ?string $date = null): ?QueueToken
    {
        $queueDate = $date ?? now()->toDateString();

        return DB::transaction(function () use ($doctorId, $queueDate) {
            QueueToken::where('doctor_id', $doctorId)
                ->whereDate('queue_date', $queueDate)
                ->where('status', QueueTokenStatus::InConsultation)
                ->update([
                    'status' => QueueTokenStatus::Completed,
                    'completed_at' => now(),
                ]);

            $next = $this->getOrderedQueue($doctorId, $queueDate)
                ->first(fn (QueueToken $t) => $t->status === QueueTokenStatus::Waiting);

            if (! $next) {
                event(new QueueUpdated($doctorId, $queueDate));

                return null;
            }

            $next->update([
                'status' => QueueTokenStatus::InConsultation,
                'called_at' => now(),
            ]);

            event(new QueueUpdated($doctorId, $queueDate));

            return $next->load(['patient', 'doctor']);
        });
    }

    public function complete(int $tokenId): QueueToken
    {
        $token = QueueToken::findOrFail($tokenId);

        $token->update([
            'status' => QueueTokenStatus::Completed,
            'completed_at' => now(),
        ]);

        if ($token->appointment_id) {
            Appointment::where('id', $token->appointment_id)
                ->update(['status' => AppointmentStatus::Completed]);
        }

        event(new QueueUpdated($token->doctor_id, $token->queue_date->toDateString()));

        return $token->load(['patient', 'doctor']);
    }

    public function cancel(int $tokenId): QueueToken
    {
        $token = QueueToken::findOrFail($tokenId);
        $token->update(['status' => QueueTokenStatus::Cancelled]);

        event(new QueueUpdated($token->doctor_id, $token->queue_date->toDateString()));

        return $token;
    }

    private function getOrderedQueue(int $doctorId, string $date)
    {
        return QueueToken::with(['patient', 'appointment'])
            ->where('doctor_id', $doctorId)
            ->whereDate('queue_date', $date)
            ->whereNotIn('status', [QueueTokenStatus::Cancelled])
            ->get()
            ->sortBy(function (QueueToken $token) {
                $priority = $token->appointment_id ? 0 : 1;
                $time = $token->appointment?->slot_time ?? $token->created_at?->format('H:i:s');

                return "{$priority}-{$time}-{$token->token_number}";
            })
            ->values();
    }
}
