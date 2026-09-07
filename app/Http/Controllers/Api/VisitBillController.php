<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisitBill;
use App\Services\VisitBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitBillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = VisitBill::with(['items', 'patient', 'doctor', 'worksheet', 'queueToken'])
            ->orderByDesc('created_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', (int) $request->patient_id);
        }
        if ($request->filled('worksheet_id')) {
            $query->where('worksheet_id', (int) $request->worksheet_id);
        }
        if ($request->filled('pharmacy_status')) {
            $query->where('pharmacy_status', $request->pharmacy_status);
        }
        if ($request->filled('lab_status')) {
            $query->where('lab_status', $request->lab_status);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->limit(100)->get());
    }

    public function show(VisitBill $visitBill): JsonResponse
    {
        return response()->json(
            $visitBill->load(['items', 'patient', 'doctor', 'worksheet', 'queueToken'])
        );
    }

    public function markPharmacyDone(VisitBill $visitBill, VisitBillService $service): JsonResponse
    {
        return response()->json($service->markSectionDone($visitBill, 'pharmacy'));
    }

    public function markLabDone(VisitBill $visitBill, VisitBillService $service): JsonResponse
    {
        return response()->json($service->markSectionDone($visitBill, 'lab'));
    }

    public function updatePharmacyItems(Request $request, VisitBill $visitBill, VisitBillService $service): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:visit_bill_items,id'],
            'items.*.included' => ['sometimes', 'boolean'],
            'items.*.qty' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.purchased' => ['sometimes', 'boolean'],
        ]);

        return response()->json($service->updatePharmacyItems($visitBill, $data['items']));
    }

    public function updateLabItems(Request $request, VisitBill $visitBill, VisitBillService $service): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:visit_bill_items,id'],
            'items.*.included' => ['sometimes', 'boolean'],
        ]);

        return response()->json($service->updateLabItems($visitBill, $data['items']));
    }
}
