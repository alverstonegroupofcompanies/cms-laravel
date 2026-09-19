<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\QueueTokenStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $today = now()->toDateString();

        return response()->json([
            'total_patients' => Patient::count(),
            'total_doctors' => Doctor::where('status', DoctorStatus::Active)->count(),
            'today_appointments' => Appointment::whereDate('appointment_date', $today)
                ->whereNotIn('status', [AppointmentStatus::Cancelled])
                ->count(),
            'today_queue_length' => QueueToken::whereDate('queue_date', $today)
                ->where('status', QueueTokenStatus::Waiting)
                ->count(),
        ]);
    }

    /**
     * Hour-of-day distribution of clinic load (staff dashboard peak-hours chart).
     * Uses booked appointment slots plus queue arrivals so the peak moves with live data.
     */
    public function peakBookingHours(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->isClinicStaff()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'range' => ['nullable', 'in:today,7d,30d,90d,month,all'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
        ]);

        $range = $data['range'] ?? null;
        if (! $range && isset($data['days'])) {
            $range = match ((int) $data['days']) {
                1 => 'today',
                7 => '7d',
                90 => '90d',
                default => '30d',
            };
        }
        $range = $range ?: 'today';

        [$from, $to, $rangeLabel, $days] = match ($range) {
            'today' => [
                now()->toDateString(),
                now()->toDateString(),
                'Today (live bookings + queue)',
                1,
            ],
            '7d' => [
                now()->subDays(6)->toDateString(),
                now()->addDays(6)->toDateString(),
                '±7 days (past + booked ahead)',
                13,
            ],
            '90d' => [
                now()->subDays(89)->toDateString(),
                now()->addDays(89)->toDateString(),
                '±90 days (past + booked ahead)',
                179,
            ],
            'month' => [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
                'This month (past + booked ahead)',
                (int) now()->daysInMonth,
            ],
            'all' => [
                null,
                null,
                'All time (past + booked ahead)',
                null,
            ],
            default => [
                now()->subDays(29)->toDateString(),
                now()->addDays(29)->toDateString(),
                '±30 days (past + booked ahead)',
                59,
            ],
        };

        $doctorId = isset($data['doctor_id']) ? (int) $data['doctor_id'] : null;
        $counts = $this->hourCountsFromAppointments($from, $to, $doctorId);

        // Queue arrivals capture walk-ins / live check-ins that shift peak during the day.
        foreach ($this->hourCountsFromQueue($from, $to, $doctorId) as $hour => $total) {
            $counts[$hour] = ($counts[$hour] ?? 0) + $total;
        }

        $hours = [];
        for ($h = 8; $h <= 20; $h++) {
            $hours[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => (int) ($counts[$h] ?? 0),
            ];
        }

        foreach ($counts as $hour => $total) {
            if ($hour < 8 || $hour > 20) {
                $hours[] = [
                    'hour' => $hour,
                    'label' => sprintf('%02d:00', $hour),
                    'count' => (int) $total,
                ];
            }
        }

        usort($hours, fn ($a, $b) => $a['hour'] <=> $b['hour']);

        $nowHour = (int) now()->format('G');
        $peak = collect($hours)
            ->filter(fn ($h) => $h['count'] > 0)
            ->sort(function ($a, $b) use ($nowHour) {
                if ($a['count'] !== $b['count']) {
                    return $b['count'] <=> $a['count'];
                }

                // Tie-break toward the hour closest to "now" so the badge tracks live load.
                return abs($a['hour'] - $nowHour) <=> abs($b['hour'] - $nowHour);
            })
            ->first();

        $total = collect($hours)->sum('count');

        return response()->json([
            'range' => $range,
            'range_label' => $rangeLabel,
            'from' => $from,
            'to' => $to ?? Appointment::query()
                ->where('status', '!=', AppointmentStatus::Cancelled->value)
                ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
                ->max('appointment_date'),
            'days' => $days,
            'total_appointments' => $total,
            'peak_hour' => $peak['hour'] ?? null,
            'peak_label' => $peak['label'] ?? null,
            'peak_count' => $peak['count'] ?? 0,
            'hours' => $hours,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function hourCountsFromAppointments(?string $from, ?string $to, ?int $doctorId): array
    {
        $driver = DB::connection()->getDriverName();
        $hourExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%H', slot_time) AS INTEGER)",
            'pgsql' => 'EXTRACT(HOUR FROM slot_time::time)::integer',
            default => 'HOUR(TIME(slot_time))',
        };

        return Appointment::query()
            ->selectRaw("{$hourExpr} as hour")
            ->selectRaw('COUNT(*) as total')
            ->when($from, fn ($q) => $q->whereDate('appointment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('appointment_date', '<=', $to))
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->hour => (int) $r->total])
            ->all();
    }

    /**
     * Live footfall by the hour patients joined the queue.
     *
     * @return array<int, int>
     */
    private function hourCountsFromQueue(?string $from, ?string $to, ?int $doctorId): array
    {
        $driver = DB::connection()->getDriverName();
        $hourExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%H', created_at) AS INTEGER)",
            'pgsql' => 'EXTRACT(HOUR FROM created_at)::integer',
            default => 'HOUR(created_at)',
        };

        // Only walk-ins without an appointment — booked slots are already counted above.
        return QueueToken::query()
            ->selectRaw("{$hourExpr} as hour")
            ->selectRaw('COUNT(*) as total')
            ->when($from, fn ($q) => $q->whereDate('queue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('queue_date', '<=', $to))
            ->whereNull('appointment_id')
            ->whereNotIn('status', [QueueTokenStatus::Cancelled->value])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->hour => (int) $r->total])
            ->all();
    }
}
