<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\QueueTokenStatus;
use App\Events\QueueUpdated;
use App\Models\Appointment;
use App\Models\QueueToken;
use App\Models\Worksheet;
use App\Services\SlotGeneratorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QueueService
{
    public function joinQueue(
        int $patientId,
        int $doctorId,
        ?string $date = null,
        ?int $appointmentId = null,
        bool $endOfLine = false,
        bool $checkIn = true
    ): QueueToken {
        $queueDate = $date ?? now()->toDateString();

        // Pure walk-in / queue join (no prior booked appointment): doctor must work that day.
        if (! $appointmentId) {
            $works = app(SlotGeneratorService::class)->doctorWorksOnDate($doctorId, $queueDate);
            if (! $works) {
                throw new InvalidArgumentException('Doctor not available today.');
            }
        }

        if ($appointmentId) {
            $existing = QueueToken::where('appointment_id', $appointmentId)
                ->whereDate('queue_date', $queueDate)
                ->whereNotIn('status', [QueueTokenStatus::Cancelled])
                ->first();

            if ($existing) {
                if ($checkIn && $existing->status === QueueTokenStatus::Waiting) {
                    Appointment::where('id', $appointmentId)
                        ->whereIn('status', [AppointmentStatus::Booked, AppointmentStatus::NoShow])
                        ->update(['status' => AppointmentStatus::CheckedIn]);
                }

                return $existing->fresh()->load(['patient', 'doctor', 'appointment']);
            }
        } elseif (! $endOfLine) {
            $existing = QueueToken::where('patient_id', $patientId)
                ->where('doctor_id', $doctorId)
                ->whereNull('appointment_id')
                ->whereDate('queue_date', $queueDate)
                ->whereIn('status', [QueueTokenStatus::Waiting, QueueTokenStatus::InConsultation])
                ->first();

            if ($existing) {
                return $existing->fresh()->load(['patient', 'doctor', 'appointment']);
            }
        }

        return DB::transaction(function () use ($patientId, $doctorId, $queueDate, $appointmentId, $endOfLine, $checkIn) {
            // Clinic-wide daily sequence: AMC01, AMC02... resets each day; unique per date.
            $lastToken = QueueToken::whereDate('queue_date', $queueDate)
                ->lockForUpdate()
                ->orderByDesc('token_number')
                ->first();

            $tokenNumber = ($lastToken?->token_number ?? 0) + 1;
            $displayCode = 'AMC'.str_pad((string) $tokenNumber, 2, '0', STR_PAD_LEFT);

            $token = QueueToken::create([
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'queue_date' => $queueDate,
                'token_number' => $tokenNumber,
                'display_code' => $displayCode,
                'status' => QueueTokenStatus::Waiting,
                'end_of_line' => $endOfLine,
                'appointment_id' => $appointmentId,
            ]);

            if ($appointmentId && $checkIn) {
                Appointment::where('id', $appointmentId)
                    ->whereIn('status', [AppointmentStatus::Booked, AppointmentStatus::NoShow])
                    ->update(['status' => AppointmentStatus::CheckedIn]);
            }

            event(new QueueUpdated($doctorId, $queueDate));

            return $token->load(['patient', 'doctor', 'appointment']);
        });
    }

    /**
     * Reception desk board for a date: queue tokens + pending online bookings,
     * sorted by slot time, with late-arrival flags.
     *
     * @return array{rows: array<int, array<string, mixed>>, late: array<int, array<string, mixed>>}
     */
    public function getReceptionDayBoard(string $date, ?int $doctorId = null): array
    {
        $now = Carbon::now();
        $isToday = $date === $now->toDateString();

        $tokens = QueueToken::with(['patient', 'doctor', 'appointment', 'worksheet'])
            ->whereDate('queue_date', $date)
            ->whereNotIn('status', [QueueTokenStatus::Cancelled])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->get();

        $tokenAppointmentIds = $tokens->pluck('appointment_id')->filter()->all();

        $pendingAppointments = Appointment::with(['patient', 'doctor'])
            ->whereDate('appointment_date', $date)
            ->whereIn('status', [AppointmentStatus::Booked, AppointmentStatus::NoShow])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->when(! empty($tokenAppointmentIds), fn ($q) => $q->whereNotIn('id', $tokenAppointmentIds))
            ->get();

        $rows = [];

        foreach ($tokens as $token) {
            $slotTime = $token->appointment?->slot_time
                ? Carbon::parse($token->appointment->slot_time)->format('H:i:s')
                : null;
            $apptStatus = $token->appointment?->status?->value ?? ($token->appointment?->status ?? null);

            // In-queue tokens are never Late; end_of_line only affects sort + EOL badge.
            $isLate = false;
            $endOfLine = (bool) $token->end_of_line;
            $sortTime = $endOfLine
                ? 'zz-'.$token->created_at?->format('H:i:s')
                : ($slotTime ?? ($token->created_at?->format('H:i:s') ?? '99:99:99'));
            $boardStatus = $this->boardStatus($token->status->value, $apptStatus, $isLate);
            $reservedOnly = $boardStatus === 'booked';

            $rows[] = [
                'kind' => 'token',
                'id' => $token->id,
                'token_id' => $token->id,
                'token_number' => $token->token_number,
                'display_code' => $token->display_code,
                'worksheet_id' => $token->worksheet?->id,
                'worksheet_code' => $token->worksheet?->worksheet_code,
                'status' => $token->status->value,
                'board_status' => $boardStatus,
                'is_late' => $isLate,
                'end_of_line' => $endOfLine,
                // Reserved booking → arrival check-in; already in queue → send to doctor.
                'can_check_in' => $token->status === QueueTokenStatus::Waiting,
                'can_checkout' => $token->status === QueueTokenStatus::InConsultation,
                'reserved_only' => $reservedOnly,
                'queue_date' => $token->queue_date?->toDateString(),
                'slot_time' => $slotTime,
                'sort_time' => $sortTime,
                'called_at' => $token->called_at,
                'completed_at' => $token->completed_at,
                'checked_out_at' => $token->status === QueueTokenStatus::Completed ? $token->completed_at : null,
                'created_at' => $token->created_at,
                'source' => $token->appointment?->type === 'walk_in' || ! $token->appointment_id ? 'walk_in' : 'online',
                'patient' => $this->patientPayload($token->patient),
                'doctor' => $this->doctorPayload($token->doctor),
                'appointment' => $token->appointment ? [
                    'id' => $token->appointment->id,
                    'slot_time' => $slotTime,
                    'status' => $apptStatus,
                    'type' => $token->appointment->type,
                ] : null,
            ];
        }

        foreach ($pendingAppointments as $appointment) {
            $slotTime = $appointment->slot_time
                ? Carbon::parse($appointment->slot_time)->format('H:i:s')
                : null;
            $isLate = $isToday && $slotTime && $now->gt(Carbon::parse($date.' '.$slotTime));
            $isMissed = ($appointment->status?->value ?? $appointment->status) === AppointmentStatus::NoShow->value;
            if ($isMissed) {
                $isLate = true;
            }

            $rows[] = [
                'kind' => 'appointment',
                'id' => 'appt-'.$appointment->id,
                'token_id' => null,
                'token_number' => null,
                'display_code' => '—',
                'status' => $isMissed ? 'no_show' : 'pending_arrival',
                'board_status' => $isLate ? 'late' : 'pending_arrival',
                'is_late' => $isLate,
                'end_of_line' => $isLate,
                'can_check_in' => true,
                'can_checkout' => false,
                'queue_date' => $date,
                'slot_time' => $slotTime,
                'sort_time' => $isLate ? 'zz-pending-'.$appointment->id : ($slotTime ?? '99:99:99'),
                'called_at' => null,
                'completed_at' => null,
                'checked_out_at' => null,
                'created_at' => $appointment->created_at,
                'source' => $appointment->type === 'walk_in' ? 'walk_in' : 'online',
                'patient' => $this->patientPayload($appointment->patient),
                'doctor' => $this->doctorPayload($appointment->doctor),
                'appointment' => [
                    'id' => $appointment->id,
                    'slot_time' => $slotTime,
                    'status' => $appointment->status?->value ?? $appointment->status,
                    'type' => $appointment->type,
                ],
            ];
        }

        usort($rows, function (array $a, array $b) {
            $cmp = strcmp((string) $a['sort_time'], (string) $b['sort_time']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a['token_number'] ?? 9999) <=> ($b['token_number'] ?? 9999);
        });

        $late = array_values(array_filter($rows, fn (array $r) => ! empty($r['is_late'])));

        $doctorIds = collect($rows)
            ->map(fn (array $r) => $r['doctor']['id'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $averages = $this->averageConsultMinutesByDoctor($doctorIds);

        foreach ($rows as &$row) {
            $id = $row['doctor']['id'] ?? null;
            $row['avg_consult_minutes'] = $id ? ($averages[(int) $id] ?? 30) : 30;
        }
        unset($row);

        foreach ($late as &$lateRow) {
            $id = $lateRow['doctor']['id'] ?? null;
            $lateRow['avg_consult_minutes'] = $id ? ($averages[(int) $id] ?? 30) : 30;
        }
        unset($lateRow);

        return [
            'rows' => array_values($rows),
            'late' => $late,
            'consult_averages' => $averages,
        ];
    }

    /**
     * Typical consult length per doctor from recent completed visits.
     * Falls back toward 30 minutes until enough history exists.
     *
     * @param  array<int, int|string>  $doctorIds
     * @return array<int, int>
     */
    public function averageConsultMinutesByDoctor(array $doctorIds, int $lookbackDays = 30): array
    {
        $defaults = [];
        foreach ($doctorIds as $id) {
            $defaults[(int) $id] = 30;
        }

        if ($defaults === []) {
            return $defaults;
        }

        $since = now()->subDays($lookbackDays)->startOfDay();

        $stats = QueueToken::query()
            ->select('doctor_id')
            ->selectRaw('COUNT(*) as samples')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, called_at, completed_at)) as avg_mins')
            ->whereIn('doctor_id', array_keys($defaults))
            ->where('status', QueueTokenStatus::Completed)
            ->whereNotNull('called_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->whereRaw('TIMESTAMPDIFF(MINUTE, called_at, completed_at) BETWEEN 3 AND 180')
            ->groupBy('doctor_id')
            ->get();

        foreach ($stats as $row) {
            $doctorId = (int) $row->doctor_id;
            $samples = (int) $row->samples;
            $avg = (float) $row->avg_mins;

            if ($samples >= 3) {
                $minutes = (int) round($avg);
            } else {
                // Blend sparse history with the 30-min default so early estimates stay stable.
                $minutes = (int) round((($avg * $samples) + (30 * (3 - $samples))) / 3);
            }

            $defaults[$doctorId] = max(10, min(90, $minutes));
        }

        return $defaults;
    }

    /** @deprecated use getReceptionDayBoard */
    public function getDayTokens(string $date): array
    {
        return $this->getReceptionDayBoard($date)['rows'];
    }

    private function boardStatus(string $tokenStatus, ?string $apptStatus, bool $isLate): string
    {
        if ($tokenStatus === QueueTokenStatus::Completed->value) {
            return 'checked_out';
        }
        if ($tokenStatus === QueueTokenStatus::InConsultation->value) {
            return 'in_consultation';
        }
        if ($isLate) {
            return 'late';
        }
        // Reserved at booking time, patient not yet arrived.
        if (
            $tokenStatus === QueueTokenStatus::Waiting->value
            && $apptStatus === AppointmentStatus::Booked->value
        ) {
            return 'booked';
        }
        if ($apptStatus === AppointmentStatus::CheckedIn->value || $tokenStatus === QueueTokenStatus::Waiting->value) {
            return 'checked_in';
        }

        return $tokenStatus;
    }

    private function patientPayload($patient): ?array
    {
        if (! $patient) {
            return null;
        }

        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'patient_code' => $patient->patient_code,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'gender' => $patient->gender,
        ];
    }

    private function doctorPayload($doctor): ?array
    {
        if (! $doctor) {
            return null;
        }

        return [
            'id' => $doctor->id,
            'name' => $doctor->name,
            'specialization' => $doctor->specialization,
        ];
    }

    public function checkInAppointment(Appointment $appointment): QueueToken
    {
        $allowed = [AppointmentStatus::Booked, AppointmentStatus::CheckedIn, AppointmentStatus::NoShow];
        if (! in_array($appointment->status, $allowed, true)) {
            throw new InvalidArgumentException('Only booked or missed appointments can be checked in.');
        }

        $date = $appointment->appointment_date->toDateString();
        $slotTime = $appointment->slot_time
            ? Carbon::parse($appointment->slot_time)->format('H:i:s')
            : null;
        $isLate = $slotTime && Carbon::now()->gt(Carbon::parse($date.' '.$slotTime));
        $wasMissed = $appointment->status === AppointmentStatus::NoShow;

        // Late / missed: new token at end of line — does not jump ahead of next patients or reuse slot priority.
        if ($isLate || $wasMissed) {
            return DB::transaction(function () use ($appointment, $date) {
                QueueToken::query()
                    ->where('appointment_id', $appointment->id)
                    ->whereIn('status', [QueueTokenStatus::Waiting])
                    ->update(['status' => QueueTokenStatus::Cancelled]);

                $appointment->update(['status' => AppointmentStatus::CheckedIn]);

                $token = $this->joinQueue(
                    $appointment->patient_id,
                    $appointment->doctor_id,
                    $date,
                    $appointment->id,
                    true // end of line
                );

                return $token->load(['patient', 'doctor', 'appointment']);
            });
        }

        $existing = QueueToken::query()
            ->where('appointment_id', $appointment->id)
            ->whereNotIn('status', [QueueTokenStatus::Cancelled])
            ->first();

        $appointment->update(['status' => AppointmentStatus::CheckedIn]);

        if ($existing) {
            return $existing->fresh()->load(['patient', 'doctor', 'appointment']);
        }

        return $this->joinQueue(
            $appointment->patient_id,
            $appointment->doctor_id,
            $date,
            $appointment->id,
            false
        );
    }

    public function getQueueStatus(int $doctorId, string $date): array
    {
        $tokens = $this->getOrderedQueue($doctorId, $date);
        $now = Carbon::now();
        $hasActiveConsult = $tokens->contains(
            fn (QueueToken $t) => $t->status === QueueTokenStatus::InConsultation
        );

        return $tokens->map(function (QueueToken $token, int $index) use ($now, $hasActiveConsult) {
            $slotTime = $token->appointment?->slot_time
                ? Carbon::parse($token->appointment->slot_time)->format('H:i:s')
                : null;

            $joinedAt = $token->created_at;
            $waitingMinutes = null;
            if (
                $token->status === QueueTokenStatus::Waiting
                && $joinedAt
            ) {
                $waitingMinutes = max(0, $joinedAt->diffInMinutes($now));
            }

            $isWaiting = $token->status === QueueTokenStatus::Waiting;
            $isConsulting = $token->status === QueueTokenStatus::InConsultation;

            return [
                'id' => $token->id,
                'token_number' => $token->token_number,
                'display_code' => $token->display_code,
                'status' => $token->status->value,
                'position' => $index + 1,
                'slot_time' => $slotTime,
                'joined_at' => $joinedAt?->toIso8601String(),
                'called_at' => $token->called_at?->toIso8601String(),
                'waiting_minutes' => $waitingMinutes,
                'end_of_line' => (bool) $token->end_of_line,
                // Ready in queue → check in to doctor; in consult → check out after visit.
                'can_check_in' => $isWaiting,
                'can_checkout' => $isConsulting,
                'has_active_consult' => $hasActiveConsult,
                'worksheet_id' => $token->worksheet?->id,
                'worksheet_code' => $token->worksheet?->worksheet_code,
                'patient' => [
                    'id' => $token->patient->id,
                    'name' => $token->patient->name,
                    'patient_code' => $token->patient->patient_code,
                ],
                'appointment_id' => $token->appointment_id,
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

        return $this->positionPayload($token, $date);
    }

    /**
     * Patient's active queue visit for a date (any doctor).
     */
    public function getPatientActiveQueue(int $patientId, ?string $date = null): ?array
    {
        $queueDate = $date ?? now()->toDateString();

        $token = QueueToken::with(['doctor', 'appointment', 'patient'])
            ->where('patient_id', $patientId)
            ->whereDate('queue_date', $queueDate)
            ->whereIn('status', [QueueTokenStatus::Waiting, QueueTokenStatus::InConsultation])
            ->orderByRaw("CASE WHEN status = 'in_consultation' THEN 0 ELSE 1 END")
            ->orderBy('token_number')
            ->first();

        if (! $token) {
            return null;
        }

        return $this->positionPayload($token, $queueDate);
    }

    private function positionPayload(QueueToken $token, string $date): array
    {
        $queue = $this->getOrderedQueue($token->doctor_id, $date)
            ->filter(fn (QueueToken $t) => in_array($t->status, [QueueTokenStatus::Waiting, QueueTokenStatus::InConsultation], true))
            ->values();

        $position = $queue->search(fn (QueueToken $t) => $t->id === $token->id);
        $isWaiting = $token->status === QueueTokenStatus::Waiting;
        $isConsulting = $token->status === QueueTokenStatus::InConsultation;

        $consultMinutes = $this->averageConsultMinutesByDoctor([(int) $token->doctor_id])[(int) $token->doctor_id] ?? 30;
        $activeConsult = $queue->first(fn (QueueToken $t) => $t->status === QueueTokenStatus::InConsultation);
        $remaining = $consultMinutes;
        if ($activeConsult?->called_at) {
            $remaining = max(0, $consultMinutes - $activeConsult->called_at->diffInMinutes(now()));
        }

        $estimatedWait = 0;
        $statusLabel = $isConsulting ? 'With doctor' : 'In queue';
        if ($isConsulting) {
            $estimatedWait = $remaining;
        } elseif ($isWaiting && $position !== false) {
            $waitingAhead = $queue
                ->take($position)
                ->filter(fn (QueueToken $t) => $t->status === QueueTokenStatus::Waiting)
                ->count();
            if ($activeConsult) {
                // Never report 0 while someone is still with the doctor.
                $estimatedWait = max(1, $remaining) + ($waitingAhead * $consultMinutes);
            } else {
                $estimatedWait = $waitingAhead * $consultMinutes;
                if ($waitingAhead === 0) {
                    $statusLabel = 'Ready to enter';
                }
            }
        }

        return [
            'token' => $token->loadMissing(['doctor', 'appointment', 'patient']),
            'position' => $isWaiting && $position !== false ? $position + 1 : ($isConsulting ? 0 : null),
            'estimated_wait_minutes' => $estimatedWait,
            'avg_consult_minutes' => $consultMinutes,
            'queue_length' => $queue->count(),
            'status' => $token->status->value,
            'status_label' => $statusLabel,
            'display_code' => $token->display_code,
            'joined_at' => $token->created_at?->toIso8601String(),
            'called_at' => $token->called_at?->toIso8601String(),
        ];
    }

    public function callNext(int $doctorId, ?string $date = null): ?QueueToken
    {
        $queueDate = $date ?? now()->toDateString();

        return DB::transaction(function () use ($doctorId, $queueDate) {
            $this->completeActiveConsultations($doctorId, $queueDate);

            $next = $this->getOrderedQueue($doctorId, $queueDate)
                ->first(fn (QueueToken $t) => $t->status === QueueTokenStatus::Waiting);

            if (! $next) {
                event(new QueueUpdated($doctorId, $queueDate));

                return null;
            }

            return $this->startConsultation($next);
        });
    }

    /**
     * Check in a specific waiting patient to the doctor (start consultation).
     * Any patient already with the doctor is checked out first.
     */
    public function checkInToConsult(int $tokenId): QueueToken
    {
        return DB::transaction(function () use ($tokenId) {
            $token = QueueToken::with(['patient', 'doctor', 'appointment'])->findOrFail($tokenId);

            if ($token->status !== QueueTokenStatus::Waiting) {
                throw new \InvalidArgumentException('Only waiting patients can be checked in to the doctor.');
            }

            $queueDate = $token->queue_date->toDateString();
            $this->completeActiveConsultations($token->doctor_id, $queueDate);

            $token = $token->fresh(['patient', 'doctor', 'appointment']);

            return $this->startConsultation($token);
        });
    }

    /**
     * Check out after meeting the doctor (complete consultation).
     */
    public function checkOutFromConsult(int $tokenId): QueueToken
    {
        $token = QueueToken::findOrFail($tokenId);

        if ($token->status !== QueueTokenStatus::InConsultation) {
            throw new \InvalidArgumentException('Only patients with the doctor can be checked out.');
        }

        return $this->complete($tokenId);
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

        $this->closeWorksheetForToken($token->id);

        event(new QueueUpdated($token->doctor_id, $token->queue_date->toDateString()));

        return $token->load(['patient', 'doctor', 'worksheet']);
    }

    private function completeActiveConsultations(int $doctorId, string $queueDate): void
    {
        $active = QueueToken::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('queue_date', $queueDate)
            ->where('status', QueueTokenStatus::InConsultation)
            ->get();

        foreach ($active as $token) {
            $token->update([
                'status' => QueueTokenStatus::Completed,
                'completed_at' => now(),
            ]);

            if ($token->appointment_id) {
                Appointment::where('id', $token->appointment_id)
                    ->update(['status' => AppointmentStatus::Completed]);
            }

            $this->closeWorksheetForToken($token->id);
        }
    }

    private function startConsultation(QueueToken $token): QueueToken
    {
        $token->update([
            'status' => QueueTokenStatus::InConsultation,
            'called_at' => now(),
        ]);

        if ($token->appointment_id) {
            Appointment::where('id', $token->appointment_id)
                ->where('status', '!=', AppointmentStatus::Completed)
                ->update(['status' => AppointmentStatus::CheckedIn]);
        }

        $this->ensureWorksheetForToken($token);

        event(new QueueUpdated($token->doctor_id, $token->queue_date->toDateString()));

        return $token->fresh()->load(['patient', 'doctor', 'worksheet']);
    }

    /** Create a unique clinical worksheet for this consult visit if missing. */
    public function ensureWorksheetForToken(QueueToken $token): Worksheet
    {
        $existing = Worksheet::where('queue_token_id', $token->id)->first();
        if ($existing) {
            return $existing;
        }

        return Worksheet::create([
            'worksheet_code' => $this->nextWorksheetCode(),
            'patient_id' => $token->patient_id,
            'doctor_id' => $token->doctor_id,
            'queue_token_id' => $token->id,
            'appointment_id' => $token->appointment_id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function closeWorksheetForToken(int $tokenId): void
    {
        $worksheets = Worksheet::with(['doctor', 'prescriptions.items.medicine', 'labOrders.items.labTest'])
            ->where('queue_token_id', $tokenId)
            ->where('status', 'open')
            ->get();

        foreach ($worksheets as $worksheet) {
            $worksheet->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            app(VisitBillService::class)->createFromWorksheet($worksheet);
        }
    }

    private function nextWorksheetCode(): string
    {
        $prefix = 'WS-'.now()->format('Ymd').'-';
        $last = Worksheet::where('worksheet_code', 'like', $prefix.'%')
            ->orderByDesc('worksheet_code')
            ->value('worksheet_code');

        $seq = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function cancel(int $tokenId): QueueToken
    {
        $token = QueueToken::findOrFail($tokenId);
        $token->update(['status' => QueueTokenStatus::Cancelled]);

        $this->closeWorksheetForToken($token->id);

        event(new QueueUpdated($token->doctor_id, $token->queue_date->toDateString()));

        return $token;
    }

    private function getOrderedQueue(int $doctorId, string $date)
    {
        $now = Carbon::now();
        $isToday = $date === $now->toDateString();

        return QueueToken::with(['patient', 'appointment', 'worksheet'])
            ->where('doctor_id', $doctorId)
            ->whereDate('queue_date', $date)
            ->whereNotIn('status', [QueueTokenStatus::Cancelled])
            ->get()
            ->sortBy(function (QueueToken $token) use ($date, $now, $isToday) {
                $slotTime = $token->appointment?->slot_time
                    ? Carbon::parse($token->appointment->slot_time)->format('H:i:s')
                    : null;

                $pastSlot = $isToday && $slotTime && $now->gt(Carbon::parse($date.' '.$slotTime));
                $endOfLine = (bool) $token->end_of_line
                    || ! $token->appointment_id
                    || ($pastSlot && $token->status === QueueTokenStatus::Waiting);

                // 0 = on-time booked (keep slot order). 1 = late / walk-in / new ticket (end of line).
                $priority = $endOfLine ? 1 : 0;
                $time = $endOfLine
                    ? str_pad((string) $token->token_number, 6, '0', STR_PAD_LEFT)
                    : ($slotTime ?? '99:99:99');

                return "{$priority}-{$time}-{$token->token_number}";
            })
            ->values();
    }

    /**
     * TV screen display data: active doctors with their current token being served
     * and upcoming queue tokens, formatted for horizontal / vertical public displays.
     *
     * @return array{
     *   clinic: array<string, mixed>,
     *   date: string,
     *   server_time: string,
     *   doctors: array<int, array<string, mixed>>,
     *   latest_call: ?array<string, mixed>
     * }
     */
    public function getTvDisplayStatus(?int $doctorId = null, ?string $date = null): array
    {
        $queueDate = $date ?? now()->toDateString();
        $now = Carbon::now();

        $clinic = \App\Models\Clinic::first();
        $clinicData = [
            'name' => $clinic?->name ?? 'Technopark Healthcare Clinic',
            'code' => $clinic?->code ?? 'AMC',
            'address' => $clinic?->address ?? '',
        ];

        $doctorsQuery = \App\Models\Doctor::with(['department', 'clinic'])
            ->where('status', \App\Enums\DoctorStatus::Active);

        if ($doctorId) {
            $doctorsQuery->where('id', $doctorId);
        }

        $doctors = $doctorsQuery->orderBy('name')->get();

        $doctorsList = [];
        $latestCall = null;
        $latestCallTimestamp = null;

        foreach ($doctors as $doctor) {
            $allTokens = $this->getOrderedQueue($doctor->id, $queueDate);

            // Tokens in consultation
            $currentTokenModel = $allTokens->first(fn (QueueToken $t) => $t->status === QueueTokenStatus::InConsultation);

            // Completed count today
            $completedCount = QueueToken::where('doctor_id', $doctor->id)
                ->whereDate('queue_date', $queueDate)
                ->where('status', QueueTokenStatus::Completed)
                ->count();

            // Waiting tokens
            $waitingTokens = $allTokens->filter(fn (QueueToken $t) => $t->status === QueueTokenStatus::Waiting)->values();

            $currentToken = null;
            if ($currentTokenModel) {
                $elapsed = $currentTokenModel->called_at
                    ? max(0, $currentTokenModel->called_at->diffInMinutes($now))
                    : 0;

                $patientName = $currentTokenModel->patient?->name ?? 'Patient';
                $nameParts = explode(' ', trim($patientName));
                $shortName = count($nameParts) > 1
                    ? $nameParts[0] . ' ' . strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1)) . '.'
                    : $patientName;

                $currentToken = [
                    'id' => $currentTokenModel->id,
                    'token_number' => $currentTokenModel->token_number,
                    'display_code' => $currentTokenModel->display_code,
                    'patient_name' => $shortName,
                    'patient_full_name' => $patientName,
                    'patient_code' => $currentTokenModel->patient?->patient_code,
                    'called_at' => $currentTokenModel->called_at?->toIso8601String(),
                    'elapsed_minutes' => $elapsed,
                    'status' => 'in_consultation',
                ];

                if ($currentTokenModel->called_at) {
                    $ts = $currentTokenModel->called_at->getTimestamp();
                    if ($latestCallTimestamp === null || $ts > $latestCallTimestamp) {
                        $latestCallTimestamp = $ts;
                        $latestCall = [
                            'token_id' => $currentTokenModel->id,
                            'token_code' => $currentTokenModel->display_code,
                            'doctor_id' => $doctor->id,
                            'doctor_name' => $doctor->name,
                            'room_number' => $doctor->room_number,
                            'patient_name' => $shortName,
                            'called_at' => $currentTokenModel->called_at->toIso8601String(),
                        ];
                    }
                }
            }

            $upcomingList = [];
            foreach ($waitingTokens->take(8) as $idx => $waitingToken) {
                $pName = $waitingToken->patient?->name ?? 'Patient';
                $pParts = explode(' ', trim($pName));
                $sName = count($pParts) > 1
                    ? $pParts[0] . ' ' . strtoupper(substr($pParts[count($pParts) - 1], 0, 1)) . '.'
                    : $pName;

                $slotTime = $waitingToken->appointment?->slot_time
                    ? Carbon::parse($waitingToken->appointment->slot_time)->format('h:i A')
                    : null;

                $upcomingList[] = [
                    'id' => $waitingToken->id,
                    'token_number' => $waitingToken->token_number,
                    'display_code' => $waitingToken->display_code,
                    'patient_name' => $sName,
                    'position' => $idx + 1,
                    'slot_time' => $slotTime,
                    'waiting_minutes' => $waitingToken->created_at ? max(0, $waitingToken->created_at->diffInMinutes($now)) : 0,
                ];
            }

            // Doctor status indicator
            $statusLabel = 'available';
            if ($currentToken) {
                $statusLabel = 'in_consultation';
            } elseif ($waitingTokens->count() > 0) {
                $statusLabel = 'calling_next';
            }

            $doctorsList[] = [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'specialization' => $doctor->specialization,
                'room_number' => $doctor->room_number,
                'department' => $doctor->department ? [
                    'id' => $doctor->department->id,
                    'name' => $doctor->department->name,
                ] : null,
                'photo_url' => $doctor->photo_url,
                'status' => $statusLabel,
                'current_token' => $currentToken,
                'waiting_tokens' => $upcomingList,
                'waiting_count' => $waitingTokens->count(),
                'completed_count' => $completedCount,
                'total_today' => ($currentToken ? 1 : 0) + $waitingTokens->count() + $completedCount,
            ];
        }

        // Prioritize doctors with active consultations and busy waiting lines first
        usort($doctorsList, function (array $a, array $b) {
            $aPriority = ($a['current_token'] ? 20 : 0) + min(10, $a['waiting_count']);
            $bPriority = ($b['current_token'] ? 20 : 0) + min(10, $b['waiting_count']);

            if ($aPriority !== $bPriority) {
                return $bPriority <=> $aPriority;
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'clinic' => $clinicData,
            'date' => $queueDate,
            'server_time' => $now->toIso8601String(),
            'doctors' => $doctorsList,
            'latest_call' => $latestCall,
        ];
    }
}
