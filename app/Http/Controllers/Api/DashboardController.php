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
}
