<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Dispense;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\VisitBill;
use App\Models\VisitBillItem;
use App\Services\VisitBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    private function withRelations()
    {
        return [
            'patient',
            'doctor',
            'items.medicine',
            'queueToken:id,display_code,token_number,queue_date',
            'worksheet:id,worksheet_code,queue_token_id,opened_at',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $patientId = $request->patient_id ? (int) $request->patient_id : null;

        // Patients may only list their own prescriptions.
        if ($user?->role === UserRole::Patient || $user?->role === 'patient') {
            $ownId = $user->patient?->id;
            if (! $ownId) {
                return response()->json(['data' => []]);
            }
            $patientId = $ownId;
        }

        $prescriptions = Prescription::with($this->withRelations())
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
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
            'worksheet_id' => ['nullable', 'exists:worksheets,id'],
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
                'worksheet_id' => $data['worksheet_id'] ?? null,
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

        return response()->json($prescription->load($this->withRelations()), 201);
    }

    public function show(Prescription $prescription): JsonResponse
    {
        return response()->json($prescription->load([...$this->withRelations(), 'dispense']));
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

        VisitBillItem::where('prescription_id', $prescription->id)
            ->where('section', 'pharmacy')
            ->update(['status' => 'done']);

        $billIds = VisitBillItem::where('prescription_id', $prescription->id)
            ->pluck('visit_bill_id')
            ->unique();
        foreach ($billIds as $billId) {
            $bill = VisitBill::find($billId);
            if ($bill) {
                app(VisitBillService::class)->refreshCompletion($bill);
            }
        }

        return response()->json($prescription->fresh()->load([...$this->withRelations(), 'dispense']));
    }
}
