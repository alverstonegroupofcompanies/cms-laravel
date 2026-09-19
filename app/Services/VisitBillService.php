<?php

namespace App\Services;

use App\Models\LabOrder;
use App\Models\VisitBill;
use App\Models\VisitBillItem;
use App\Models\Worksheet;
use Illuminate\Support\Facades\DB;

class VisitBillService
{
    public function createFromWorksheet(Worksheet $worksheet): ?VisitBill
    {
        $existing = VisitBill::where('worksheet_id', $worksheet->id)->first();
        if ($existing) {
            return $existing;
        }

        $worksheet->loadMissing([
            'doctor',
            'prescriptions.items.medicine',
            'labOrders.items.labTest',
        ]);

        return DB::transaction(function () use ($worksheet) {
            $consultationFee = (float) ($worksheet->doctor?->consultation_fee ?? 0);
            $pharmacySubtotal = 0.0;
            $labSubtotal = 0.0;
            $items = [];

            if ($consultationFee > 0) {
                $items[] = [
                    'section' => 'consultation',
                    'name' => 'Consultation fee',
                    'detail' => $worksheet->doctor?->name,
                    'qty' => 1,
                    'unit_price' => $consultationFee,
                    'amount' => $consultationFee,
                    'status' => 'none',
                ];
            }

            foreach ($worksheet->prescriptions as $rx) {
                foreach ($rx->items as $item) {
                    $qty = (int) ($item->quantity ?: 0);
                    $unit = (float) ($item->medicine?->unit_price ?? 0);
                    $amount = $qty * $unit;
                    $pharmacySubtotal += $amount;
                    $detail = trim(implode(' · ', array_filter([
                        $item->dosage,
                        $item->frequency,
                        $item->duration_days ? $item->duration_days.'d' : null,
                    ])));
                    $items[] = [
                        'section' => 'pharmacy',
                        'name' => $item->medicine?->name ?: 'Medicine',
                        'detail' => $detail,
                        'qty' => $qty,
                        'unit_price' => $unit,
                        'amount' => $amount,
                        'status' => 'pending',
                        'included' => true,
                        'purchased' => false,
                        'prescription_id' => $rx->id,
                        'prescription_item_id' => $item->id,
                    ];
                }
            }

            foreach ($worksheet->labOrders as $order) {
                foreach ($order->items as $item) {
                    $unit = (float) ($item->labTest?->price ?? 0);
                    $labSubtotal += $unit;
                    $items[] = [
                        'section' => 'lab',
                        'name' => $item->labTest?->name ?: 'Lab test',
                        'detail' => $item->labTest?->code,
                        'qty' => 1,
                        'unit_price' => $unit,
                        'amount' => $unit,
                        'status' => 'pending',
                        'included' => true,
                        'purchased' => false,
                        'lab_order_id' => $order->id,
                        'lab_order_item_id' => $item->id,
                    ];
                }
            }

            $pharmacyStatus = $pharmacySubtotal > 0 || collect($items)->contains(fn ($i) => $i['section'] === 'pharmacy')
                ? 'pending'
                : 'none';
            $labStatus = collect($items)->contains(fn ($i) => $i['section'] === 'lab')
                ? 'pending'
                : 'none';

            // Still create a bill even with only consult / empty so doctor can reopen visit summary.
            $bill = VisitBill::create([
                'bill_code' => $this->nextBillCode(),
                'worksheet_id' => $worksheet->id,
                'patient_id' => $worksheet->patient_id,
                'doctor_id' => $worksheet->doctor_id,
                'queue_token_id' => $worksheet->queue_token_id,
                'consultation_fee' => $consultationFee,
                'pharmacy_subtotal' => $pharmacySubtotal,
                'lab_subtotal' => $labSubtotal,
                'grand_total' => $consultationFee + $pharmacySubtotal + $labSubtotal,
                'status' => ($pharmacyStatus === 'none' && $labStatus === 'none') ? 'completed' : 'open',
                'pharmacy_status' => $pharmacyStatus,
                'lab_status' => $labStatus,
            ]);

            foreach ($items as $row) {
                $bill->items()->create($row);
            }

            return $bill->load(['items', 'patient', 'doctor', 'worksheet', 'queueToken']);
        });
    }

    public function refreshCompletion(VisitBill $bill): VisitBill
    {
        $bill->load('items');

        $pharmacyItems = $bill->items->where('section', 'pharmacy')->where('included', true);
        $labItems = $bill->items->where('section', 'lab')->where('included', true);

        if ($bill->items->where('section', 'pharmacy')->isNotEmpty()) {
            if ($pharmacyItems->isEmpty()) {
                $bill->pharmacy_status = 'done';
            } else {
                $allDone = $pharmacyItems->every(fn (VisitBillItem $i) => $i->status === 'done' || $i->purchased);
                $bill->pharmacy_status = $allDone ? 'done' : 'pending';
            }
        }

        if ($bill->items->where('section', 'lab')->isNotEmpty()) {
            if ($labItems->isEmpty()) {
                $bill->lab_status = 'done';
            } else {
                $allDone = $labItems->every(fn (VisitBillItem $i) => $i->status === 'done');
                $bill->lab_status = $allDone ? 'done' : 'pending';
            }
        }

        $pharmacyOk = in_array($bill->pharmacy_status, ['none', 'done'], true);
        $labOk = in_array($bill->lab_status, ['none', 'done'], true);

        if ($pharmacyOk && $labOk) {
            $bill->status = 'completed';
        } elseif ($bill->pharmacy_status === 'done' || $bill->lab_status === 'done') {
            $bill->status = 'in_progress';
        } else {
            $bill->status = 'open';
        }

        $bill->save();

        return $bill->fresh()->load(['items', 'patient', 'doctor', 'worksheet', 'queueToken']);
    }

    /**
     * Pharmacy edits: include/uncheck lines, change qty, mark purchased.
     *
     * @param  array<int, array{id:int, included?:bool, qty?:int, purchased?:bool}>  $lines
     */
    public function updatePharmacyItems(VisitBill $bill, array $lines): VisitBill
    {
        return DB::transaction(function () use ($bill, $lines) {
            foreach ($lines as $row) {
                $item = $bill->items()
                    ->where('id', (int) $row['id'])
                    ->where('section', 'pharmacy')
                    ->first();
                if (! $item) {
                    continue;
                }

                if (array_key_exists('included', $row)) {
                    $item->included = (bool) $row['included'];
                }
                if (array_key_exists('qty', $row)) {
                    $qty = max(0, (int) $row['qty']);
                    $item->qty = $qty;
                    $item->amount = $qty * (float) $item->unit_price;
                }
                if (array_key_exists('purchased', $row)) {
                    $item->purchased = (bool) $row['purchased'];
                    if ($item->purchased) {
                        $item->status = 'done';
                    } elseif ($item->included) {
                        $item->status = 'pending';
                    }
                }
                if (! $item->included) {
                    $item->purchased = false;
                    $item->status = 'skipped';
                    $item->amount = 0;
                } elseif ($item->status === 'skipped') {
                    $item->status = $item->purchased ? 'done' : 'pending';
                    $item->amount = $item->qty * (float) $item->unit_price;
                }

                $item->save();
            }

            $this->recalculateTotals($bill->fresh(['items']));

            return $this->refreshCompletion($bill->fresh());
        });
    }

    /**
     * Lab edits: include / skip ordered tests on the bill.
     *
     * @param  array<int, array{id:int, included?:bool}>  $lines
     */
    public function updateLabItems(VisitBill $bill, array $lines): VisitBill
    {
        return DB::transaction(function () use ($bill, $lines) {
            foreach ($lines as $row) {
                $item = $bill->items()
                    ->where('id', (int) $row['id'])
                    ->where('section', 'lab')
                    ->first();
                if (! $item) {
                    continue;
                }

                if (array_key_exists('included', $row)) {
                    $item->included = (bool) $row['included'];
                }

                if (! $item->included) {
                    $item->purchased = false;
                    $item->status = 'skipped';
                    $item->amount = 0;
                } elseif ($item->status === 'skipped') {
                    $item->status = 'pending';
                    $item->amount = $item->qty * (float) $item->unit_price;
                }

                $item->save();
            }

            $this->recalculateTotals($bill->fresh(['items']));

            return $this->refreshCompletion($bill->fresh());
        });
    }

    public function markSectionDone(VisitBill $bill, string $section): VisitBill
    {
        if ($section === 'pharmacy') {
            $bill->items()
                ->where('section', 'pharmacy')
                ->where('included', true)
                ->where('status', '!=', 'done')
                ->update([
                    'status' => 'done',
                    'purchased' => true,
                ]);
            $bill->items()
                ->where('section', 'pharmacy')
                ->where('included', false)
                ->update([
                    'status' => 'skipped',
                    'purchased' => false,
                    'amount' => 0,
                ]);
        } else {
            $bill->items()
                ->where('section', $section)
                ->where('included', true)
                ->update([
                    'status' => 'done',
                    'purchased' => true,
                ]);
            $bill->items()
                ->where('section', $section)
                ->where('included', false)
                ->update([
                    'status' => 'skipped',
                    'purchased' => false,
                    'amount' => 0,
                ]);

            // Keep linked lab orders in sync so the patient portal sees completed work.
            if ($section === 'lab') {
                $orderIds = $bill->items()
                    ->where('section', 'lab')
                    ->whereNotNull('lab_order_id')
                    ->pluck('lab_order_id')
                    ->unique()
                    ->filter();
                if ($orderIds->isNotEmpty()) {
                    $cancelledOrderIds = $bill->items()
                        ->where('section', 'lab')
                        ->whereIn('lab_order_id', $orderIds)
                        ->get()
                        ->groupBy('lab_order_id')
                        ->filter(fn ($items) => $items->every(fn (VisitBillItem $i) => ! $i->included))
                        ->keys();

                    if ($cancelledOrderIds->isNotEmpty()) {
                        LabOrder::query()
                            ->whereIn('id', $cancelledOrderIds)
                            ->where('status', '!=', 'cancelled')
                            ->update(['status' => 'cancelled']);
                    }

                    $completeOrderIds = $orderIds->diff($cancelledOrderIds);
                    if ($completeOrderIds->isNotEmpty()) {
                        LabOrder::query()
                            ->whereIn('id', $completeOrderIds)
                            ->where('status', '!=', 'cancelled')
                            ->update(['status' => 'completed']);
                    }
                }
            }
        }

        $this->recalculateTotals($bill->fresh(['items']));

        return $this->refreshCompletion($bill->fresh());
    }

    public function recalculateTotals(VisitBill $bill): VisitBill
    {
        $items = $bill->items;
        $pharmacy = $items
            ->where('section', 'pharmacy')
            ->where('included', true)
            ->sum(fn (VisitBillItem $i) => (float) $i->amount);
        $lab = $items
            ->where('section', 'lab')
            ->where('included', true)
            ->sum(fn (VisitBillItem $i) => (float) $i->amount);
        $fee = (float) $bill->consultation_fee;

        $bill->update([
            'pharmacy_subtotal' => $pharmacy,
            'lab_subtotal' => $lab,
            'grand_total' => $fee + $pharmacy + $lab,
        ]);

        return $bill->fresh();
    }

    private function nextBillCode(): string
    {
        $prefix = 'VB-'.now()->format('Ymd').'-';
        $last = VisitBill::where('bill_code', 'like', $prefix.'%')
            ->orderByDesc('bill_code')
            ->value('bill_code');

        $seq = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
