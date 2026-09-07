<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Worksheet;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorksheetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        $doctorId = isset($data['doctor_id']) ? (int) $data['doctor_id'] : null;
        $user = $request->user();
        if ($user?->role === UserRole::Doctor) {
            $doctorId = $user->doctor?->id;
            if (! $doctorId) {
                return response()->json(['message' => 'Doctor profile not found.'], 403);
            }
        }

        $worksheets = Worksheet::with(['patient', 'doctor', 'queueToken'])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->when($data['patient_id'] ?? null, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['date'] ?? null, fn ($q, $date) => $q->whereDate('opened_at', $date))
            ->latest()
            ->paginate(30);

        return response()->json($worksheets);
    }

    public function show(Request $request, Worksheet $worksheet): JsonResponse
    {
        $this->authorizeDoctorAccess($request, $worksheet);

        $worksheet->load([
            'patient',
            'doctor.department',
            'queueToken',
            'appointment',
            'prescriptions.items.medicine',
            'labOrders.items.labTest',
            'labOrders.report',
        ]);

        return response()->json($worksheet);
    }

    public function showByToken(Request $request, int $tokenId, QueueService $queueService): JsonResponse
    {
        $token = \App\Models\QueueToken::with(['patient', 'doctor', 'appointment'])->findOrFail($tokenId);
        $this->authorizeDoctorToken($request, $token);

        $worksheet = $queueService->ensureWorksheetForToken($token);
        $worksheet->load([
            'patient',
            'doctor.department',
            'queueToken',
            'appointment',
            'prescriptions.items.medicine',
            'labOrders.items.labTest',
            'labOrders.report',
        ]);

        return response()->json($worksheet);
    }

    public function update(Request $request, Worksheet $worksheet): JsonResponse
    {
        $this->authorizeDoctorAccess($request, $worksheet);

        $data = $request->validate([
            'chief_complaint' => ['nullable', 'string'],
            'clinical_notes' => ['nullable', 'string'],
            'clinical_data' => ['nullable', 'array'],
            'diagnosis' => ['nullable', 'string', 'max:500'],
            'advice' => ['nullable', 'string'],
            'follow_up' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        if (($data['status'] ?? null) === 'closed' && $worksheet->status !== 'closed') {
            $data['closed_at'] = now();
        }

        $worksheet->update($data);

        return response()->json(
            $worksheet->fresh()->load([
                'patient',
                'doctor.department',
                'queueToken',
                'prescriptions.items.medicine',
                'labOrders.items.labTest',
            ])
        );
    }

    private function authorizeDoctorAccess(Request $request, Worksheet $worksheet): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if ($user->hasRole(UserRole::Admin, UserRole::Receptionist, 'admin', 'receptionist')) {
            return;
        }

        if ($user->role === UserRole::Doctor || $user->role === 'doctor') {
            $doctorId = $user->doctor?->id;
            if ($doctorId && (int) $worksheet->doctor_id === (int) $doctorId) {
                return;
            }
            abort(403, 'You can only access your own worksheets.');
        }

        abort(403);
    }

    private function authorizeDoctorToken(Request $request, \App\Models\QueueToken $token): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if ($user->hasRole(UserRole::Admin, UserRole::Receptionist, 'admin', 'receptionist')) {
            return;
        }

        if ($user->role === UserRole::Doctor || $user->role === 'doctor') {
            $doctorId = $user->doctor?->id;
            if ($doctorId && (int) $token->doctor_id === (int) $doctorId) {
                return;
            }
            abort(403, 'You can only access your own worksheets.');
        }

        abort(403);
    }
}
