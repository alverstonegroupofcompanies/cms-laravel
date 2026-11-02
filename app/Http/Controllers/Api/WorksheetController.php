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
            ->latest('opened_at')
            ->latest('id')
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

        $this->hydrateFallbackRelations($worksheet);

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

        $this->hydrateFallbackRelations($worksheet);

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

        $fresh = $worksheet->fresh()->load([
            'patient',
            'doctor.department',
            'queueToken',
            'prescriptions.items.medicine',
            'labOrders.items.labTest',
            'labOrders.report',
        ]);

        $this->hydrateFallbackRelations($fresh);

        return response()->json($fresh);
    }

    /**
     * Ensure any prescriptions, lab orders, or clinical notes recorded
     * for this patient visit (including sibling tokens/worksheets on the same day)
     * are attached to the worksheet response so the doctor always sees complete visit data.
     */
    private function hydrateFallbackRelations(Worksheet $worksheet): void
    {
        // 1. All prescriptions associated with this worksheet, token, or patient+doctor consult on this date
        $allRx = \App\Models\Prescription::with(['items.medicine'])
            ->where(function ($q) use ($worksheet) {
                $q->where('worksheet_id', $worksheet->id);
                if ($worksheet->queue_token_id) {
                    $q->orWhere('queue_token_id', $worksheet->queue_token_id);
                }
                $q->orWhere(function ($sub) use ($worksheet) {
                    $sub->where('patient_id', $worksheet->patient_id)
                        ->where('doctor_id', $worksheet->doctor_id);
                    if ($worksheet->opened_at) {
                        $sub->whereDate('created_at', $worksheet->opened_at->toDateString());
                    }
                });
            })
            ->latest('id')
            ->get();

        if ($allRx->isNotEmpty()) {
            $worksheet->setRelation('prescriptions', $allRx);
        }

        // 2. All lab orders associated with this worksheet, token, or patient+doctor consult on this date
        $allLabs = \App\Models\LabOrder::with(['items.labTest', 'report'])
            ->where(function ($q) use ($worksheet) {
                $q->where('worksheet_id', $worksheet->id);
                if ($worksheet->queue_token_id) {
                    $q->orWhere('queue_token_id', $worksheet->queue_token_id);
                }
                $q->orWhere(function ($sub) use ($worksheet) {
                    $sub->where('patient_id', $worksheet->patient_id)
                        ->where('doctor_id', $worksheet->doctor_id);
                    if ($worksheet->opened_at) {
                        $sub->whereDate('created_at', $worksheet->opened_at->toDateString());
                    }
                });
            })
            ->latest('id')
            ->get();

        if ($allLabs->isNotEmpty()) {
            $worksheet->setRelation('labOrders', $allLabs);
        }

        // 3. Fallback clinical notes, complaints, diagnosis from sibling same-day consult if this worksheet is empty
        if (! $worksheet->diagnosis && ! $worksheet->chief_complaint && ! $worksheet->clinical_notes) {
            $sibling = Worksheet::where('patient_id', $worksheet->patient_id)
                ->where('doctor_id', $worksheet->doctor_id)
                ->where('id', '!=', $worksheet->id)
                ->where(function ($q) use ($worksheet) {
                    if ($worksheet->opened_at) {
                        $q->whereDate('opened_at', $worksheet->opened_at->toDateString());
                    }
                })
                ->where(function ($q) {
                    $q->whereNotNull('diagnosis')
                        ->orWhereNotNull('chief_complaint')
                        ->orWhereNotNull('clinical_notes');
                })
                ->latest('id')
                ->first();

            if ($sibling) {
                $worksheet->diagnosis = $worksheet->diagnosis ?? $sibling->diagnosis;
                $worksheet->chief_complaint = $worksheet->chief_complaint ?? $sibling->chief_complaint;
                $worksheet->clinical_notes = $worksheet->clinical_notes ?? $sibling->clinical_notes;
                if (! $worksheet->clinical_data && $sibling->clinical_data) {
                    $worksheet->clinical_data = $sibling->clinical_data;
                }
                if (! $worksheet->advice && $sibling->advice) {
                    $worksheet->advice = $sibling->advice;
                }
                if (! $worksheet->follow_up && $sibling->follow_up) {
                    $worksheet->follow_up = $sibling->follow_up;
                }
            }
        }
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
