<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispense;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $prescriptions = Prescription::with(['patient', 'doctor', 'items.medicine'])
            ->when($request->patient_id, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return response()->json($prescriptions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'doctor_id' => ['required', 'exists:doctors,id'],
            'queue_token_id' => ['nullable', 'exists:queue_tokens,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['required', 'exists:medicines,id'],
            'items.*.dosage' => ['required', 'string', 'max:100'],
            'items.*.frequency' => ['required', 'string', 'max:100'],
            'items.*.duration_days' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $prescription = DB::transaction(function () use ($data) {
            $prescription = Prescription::create([
                'patient_id' => $data['patient_id'],
                'doctor_id' => $data['doctor_id'],
                'queue_token_id' => $data['queue_token_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);

            foreach ($data['items'] as $item) {
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    ...$item,
                ]);
            }

            return $prescription;
        });

        return response()->json($prescription->load(['patient', 'doctor', 'items.medicine']), 201);
    }

    public function show(Prescription $prescription): JsonResponse
    {
        return response()->json($prescription->load(['patient', 'doctor', 'items.medicine', 'dispense']));
    }

    public function dispense(Request $request, Prescription $prescription): JsonResponse
    {
        if ($prescription->status === 'dispensed') {
            return response()->json(['message' => 'Already dispensed.'], 422);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($prescription, $request, $data) {
            foreach ($prescription->items as $item) {
                $medicine = Medicine::lockForUpdate()->find($item->medicine_id);
                if ($medicine->stock_quantity < $item->quantity) {
                    throw new \RuntimeException("Insufficient stock for {$medicine->name}");
                }
                $medicine->decrement('stock_quantity', $item->quantity);
            }

            Dispense::create([
                'prescription_id' => $prescription->id,
                'dispensed_by' => $request->user()->id,
                'dispensed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $prescription->update(['status' => 'dispensed']);
        });

        return response()->json($prescription->fresh()->load(['patient', 'doctor', 'items.medicine', 'dispense']));
    }
}
