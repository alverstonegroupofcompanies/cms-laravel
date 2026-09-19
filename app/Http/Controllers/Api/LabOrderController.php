<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabReport;
use App\Models\VisitBill;
use App\Models\VisitBillItem;
use App\Services\VisitBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LabOrderController extends Controller
{
    private function withRelations()
    {
        return [
            'patient',
            'doctor',
            'items.labTest',
            'report',
            'queueToken:id,display_code,token_number,queue_date',
            'worksheet:id,worksheet_code,queue_token_id,opened_at',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $patientId = $request->patient_id ? (int) $request->patient_id : null;
        $isPatient = $user?->hasRole(UserRole::Patient, 'patient') ?? false;

        if ($isPatient) {
            $ownId = $user->patient?->id
                ?? \App\Models\Patient::where('user_id', $user->id)->value('id');
            if (! $ownId) {
                return response()->json(['data' => []]);
            }
            $patientId = (int) $ownId;
        }

        $query = LabOrder::with($this->withRelations())
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest();

        // Patient portal expects a simple list (not a short paginated page).
        if ($isPatient) {
            return response()->json([
                'data' => $query->limit(100)->get(),
            ]);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Request $request, LabOrder $labOrder): JsonResponse
    {
        $user = $request->user();
        if ($user?->hasRole(UserRole::Patient, 'patient')) {
            $ownId = $user->patient?->id
                ?? \App\Models\Patient::where('user_id', $user->id)->value('id');
            if (! $ownId || (int) $labOrder->patient_id !== (int) $ownId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        return response()->json($labOrder->load($this->withRelations()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'doctor_id' => ['required', 'exists:doctors,id'],
            'queue_token_id' => ['nullable', 'exists:queue_tokens,id'],
            'worksheet_id' => ['nullable', 'exists:worksheets,id'],
            'notes' => ['nullable', 'string'],
            'test_ids' => ['required', 'array', 'min:1'],
            'test_ids.*' => ['exists:lab_tests,id'],
        ]);

        $order = DB::transaction(function () use ($data) {
            $order = LabOrder::create([
                'patient_id' => $data['patient_id'],
                'doctor_id' => $data['doctor_id'],
                'queue_token_id' => $data['queue_token_id'] ?? null,
                'worksheet_id' => $data['worksheet_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'ordered',
            ]);

            foreach ($data['test_ids'] as $testId) {
                LabOrderItem::create([
                    'lab_order_id' => $order->id,
                    'lab_test_id' => $testId,
                ]);
            }

            return $order;
        });

        return response()->json($order->load($this->withRelations()), 201);
    }

    public function uploadReport(Request $request, LabOrder $labOrder): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'result_summary' => ['nullable', 'string'],
        ]);

        if ($labOrder->report) {
            Storage::disk('public')->delete($labOrder->report->file_path);
            $labOrder->report->delete();
        }

        $path = $request->file('report')->store('lab-reports', 'public');

        $report = LabReport::create([
            'lab_order_id' => $labOrder->id,
            'file_path' => $path,
            'file_name' => $request->file('report')->getClientOriginalName(),
            'result_summary' => $data['result_summary'] ?? null,
            'uploaded_at' => now(),
        ]);

        $labOrder->update(['status' => 'completed']);

        VisitBillItem::where('lab_order_id', $labOrder->id)
            ->where('section', 'lab')
            ->update(['status' => 'done']);

        $billIds = VisitBillItem::where('lab_order_id', $labOrder->id)
            ->pluck('visit_bill_id')
            ->unique();
        foreach ($billIds as $billId) {
            $bill = VisitBill::find($billId);
            if ($bill) {
                app(VisitBillService::class)->refreshCompletion($bill);
            }
        }

        return response()->json([
            'order' => $labOrder->fresh()->load($this->withRelations()),
            'report_url' => Storage::disk('public')->url($path),
        ]);
    }

    public function saveResults(Request $request, LabOrder $labOrder): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:lab_order_items,id'],
            'items.*.results' => ['nullable', 'array'],
        ]);

        foreach ($data['items'] as $row) {
            LabOrderItem::query()
                ->where('lab_order_id', $labOrder->id)
                ->where('id', (int) $row['id'])
                ->update(['results' => $row['results'] ?? null]);
        }

        $labOrder->load('items');
        $hasResults = $labOrder->items->contains(function (LabOrderItem $item) {
            if (! is_array($item->results) || $item->results === []) {
                return false;
            }

            foreach ($item->results as $value) {
                if (trim((string) $value) !== '') {
                    return true;
                }
            }

            return false;
        });

        if ($hasResults && $labOrder->status === 'ordered') {
            $labOrder->update(['status' => 'in_progress']);
        }

        return response()->json($labOrder->fresh()->load($this->withRelations()));
    }
}
