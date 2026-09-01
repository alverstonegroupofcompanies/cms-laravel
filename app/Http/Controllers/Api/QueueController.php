<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function complete(int $id, QueueService $queueService): JsonResponse
    {
        return response()->json($queueService->complete($id));
    }

    public function cancel(int $id, QueueService $queueService): JsonResponse
    {
        return response()->json($queueService->cancel($id));
    }
}
