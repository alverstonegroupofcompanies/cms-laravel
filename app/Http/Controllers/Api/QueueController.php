<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function join(Request $request, QueueService $queueService): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'doctor_id' => ['required', 'exists:doctors,id'],
            'date' => ['nullable', 'date'],
        ]);

        $token = $queueService->joinQueue(
            (int) $data['patient_id'],
            (int) $data['doctor_id'],
            $data['date'] ?? null
        );

        $position = $queueService->getMyPosition(
            (int) $data['patient_id'],
            (int) $data['doctor_id'],
            $data['date'] ?? now()->toDateString()
        );

        return response()->json([
            'queue_token' => $token,
            'position' => $position,
        ], 201);
    }

    public function dayBoard(Request $request, QueueService $queueService): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
        ]);

        $date = $data['date'] ?? now()->toDateString();
        $doctorId = isset($data['doctor_id']) ? (int) $data['doctor_id'] : null;

        $user = $request->user();
        if ($user?->role === UserRole::Doctor) {
            $doctorId = $user->doctor?->id;
            if (! $doctorId) {
                return response()->json(['message' => 'Doctor profile not found.'], 403);
            }
        }

        $board = $queueService->getReceptionDayBoard($date, $doctorId);
        $lastNumber = \App\Models\QueueToken::whereDate('queue_date', $date)
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->max('token_number') ?? 0;
        $nextNumber = (int) $lastNumber + 1;

        return response()->json([
            'date' => $date,
            'doctor_id' => $doctorId,
            'tokens' => $board['rows'],
            'rows' => $board['rows'],
            'late' => $board['late'],
            'consult_averages' => $board['consult_averages'] ?? [],
            'next_token' => 'AMC'.str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT),
            'next_token_number' => $nextNumber,
        ]);
    }

    public function status(Request $request, QueueService $queueService): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'date' => ['nullable', 'date'],
        ]);

        return response()->json([
            'queue' => $queueService->getQueueStatus(
                (int) $data['doctor_id'],
                $data['date'] ?? now()->toDateString()
            ),
        ]);
    }

    public function myPosition(Request $request, QueueService $queueService): JsonResponse
    {
        $user = $request->user();
        $patient = $user->patient;

        if (! $patient) {
            return response()->json(['message' => 'Patient profile not found.'], 404);
        }

        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'date' => ['nullable', 'date'],
        ]);

        $position = $queueService->getMyPosition(
            $patient->id,
            (int) $data['doctor_id'],
            $data['date'] ?? now()->toDateString()
        );

        if (! $position) {
            return response()->json(['message' => 'Not in queue.'], 404);
        }

        return response()->json($position);
    }

    /** Patient portal: today's active queue visit (if any). */
    public function myActive(Request $request, QueueService $queueService): JsonResponse
    {
        $user = $request->user();
        $patient = $user->patient
            ?? \App\Models\Patient::where('user_id', $user->id)->first();

        if (! $patient) {
            return response()->json(['message' => 'Patient profile not found.'], 404);
        }

        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $active = $queueService->getPatientActiveQueue(
            $patient->id,
            $data['date'] ?? null
        );

        return response()->json([
            'in_queue' => (bool) $active,
            'queue' => $active,
        ]);
    }

    public function callNext(Request $request, QueueService $queueService): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'date' => ['nullable', 'date'],
        ]);

        $token = $queueService->callNext(
            (int) $data['doctor_id'],
            $data['date'] ?? null
        );

        if (! $token) {
            return response()->json(['message' => 'No patients waiting in queue.'], 404);
        }

        return response()->json($token);
    }

    /** Check in a waiting patient to the doctor (start consultation). */
    public function checkIn(int $id, QueueService $queueService): JsonResponse
    {
        try {
            return response()->json($queueService->checkInToConsult($id));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Check out after the doctor visit (complete consultation). Doctor-only. */
    public function checkOut(Request $request, int $id, QueueService $queueService): JsonResponse
    {
        $token = \App\Models\QueueToken::findOrFail($id);
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $isAdmin = $user->hasRole(UserRole::Admin, 'admin');
        $isTreatingDoctor = ($user->role === UserRole::Doctor || $user->role === 'doctor')
            && (int) $user->doctor?->id === (int) $token->doctor_id;

        if (! $isAdmin && ! $isTreatingDoctor) {
            return response()->json([
                'message' => 'Only the treating doctor can check out after consultation.',
            ], 403);
        }

        try {
            return response()->json($queueService->checkOutFromConsult($id));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function complete(int $id, QueueService $queueService): JsonResponse
    {
        return response()->json($queueService->complete($id));
    }

    public function cancel(int $id, QueueService $queueService): JsonResponse
    {
        return response()->json($queueService->cancel($id));
    }
}
