<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LabOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = LabOrder::with(['patient', 'doctor', 'items.labTest', 'report'])
            ->when($request->patient_id, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'doctor_id' => ['required', 'exists:doctors,id'],
            'queue_token_id' => ['nullable', 'exists:queue_tokens,id'],
            'notes' => ['nullable', 'string'],
            'test_ids' => ['required', 'array', 'min:1'],
            'test_ids.*' => ['exists:lab_tests,id'],
        ]);

        $order = DB::transaction(function () use ($data) {
            $order = LabOrder::create([
                'patient_id' => $data['patient_id'],
                'doctor_id' => $data['doctor_id'],
                'queue_token_id' => $data['queue_token_id'] ?? null,
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

        return response()->json($order->load(['patient', 'doctor', 'items.labTest']), 201);
    }

    public function show(LabOrder $labOrder): JsonResponse
    {
        return response()->json($labOrder->load(['patient', 'doctor', 'items.labTest', 'report']));
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

        return response()->json([
            'order' => $labOrder->fresh()->load(['patient', 'doctor', 'items.labTest', 'report']),
            'report_url' => Storage::disk('public')->url($path),
        ]);
    }
}
