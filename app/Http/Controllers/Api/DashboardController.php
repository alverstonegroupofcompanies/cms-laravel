<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\QueueTokenStatus;
use App\Enums\UserRole;
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
     * Hour-of-day distribution of appointment slots (staff dashboard peak-hours chart).
     * Includes past visits and already-booked future appointments in the selected window.
     */
    public function peakBookingHours(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->isClinicStaff()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'range' => ['nullable', 'in:7d,30d,90d,month,all'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
        ]);

        $range = $data['range'] ?? null;
        if (! $range && isset($data['days'])) {
            $range = match ((int) $data['days']) {
                7 => '7d',
                90 => '90d',
                default => '30d',
            };
        }
        $range = $range ?: '30d';

        // Window spans recent history + upcoming booked slots so peak hours reflect real load.
        [$from, $to, $rangeLabel, $days] = match ($range) {
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

        $driver = DB::connection()->getDriverName();
        $hourExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%H', slot_time) AS INTEGER)",
            'pgsql' => 'EXTRACT(HOUR FROM slot_time)::integer',
            default => 'HOUR(slot_time)',
        };

        $rows = Appointment::query()
            ->selectRaw("{$hourExpr} as hour")
            ->selectRaw('COUNT(*) as total')
            ->when($from, fn ($q) => $q->whereDate('appointment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('appointment_date', '<=', $to))
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->when(
                isset($data['doctor_id']),
                fn ($q) => $q->where('doctor_id', (int) $data['doctor_id'])
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy(fn ($r) => (int) $r->hour);

        $hours = [];
        for ($h = 8; $h <= 20; $h++) {
            $hours[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => (int) ($rows[$h]->total ?? 0),
            ];
        }

        foreach ($rows as $hour => $row) {
            if ($hour < 8 || $hour > 20) {
                $hours[] = [
                    'hour' => $hour,
                    'label' => sprintf('%02d:00', $hour),
                    'count' => (int) $row->total,
                ];
            }
        }

        usort($hours, fn ($a, $b) => $a['hour'] <=> $b['hour']);

        $peak = collect($hours)->sortByDesc('count')->first();
        $total = collect($hours)->sum('count');

        return response()->json([
            'range' => $range,
            'range_label' => $rangeLabel,
            'from' => $from,
            'to' => $to ?? Appointment::query()
                ->where('status', '!=', AppointmentStatus::Cancelled->value)
                ->when(
                    isset($data['doctor_id']),
                    fn ($q) => $q->where('doctor_id', (int) $data['doctor_id'])
                )
                ->max('appointment_date'),
            'days' => $days,
            'total_appointments' => $total,
            'peak_hour' => ($peak && $peak['count'] > 0) ? $peak['hour'] : null,
            'peak_label' => ($peak && $peak['count'] > 0) ? $peak['label'] : null,
            'peak_count' => ($peak && $peak['count'] > 0) ? $peak['count'] : 0,
            'hours' => $hours,
        ]);
    }
}
