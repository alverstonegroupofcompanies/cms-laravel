<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisitBillItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PharmacySalesController extends Controller
{
    /**
     * Date-wise sold medicine counts from completed pharmacy bill lines.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay()
            : now()->endOfDay();
        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $rows = VisitBillItem::query()
            ->from('visit_bill_items as i')
            ->where('i.section', 'pharmacy')
            ->where('i.included', true)
            ->where(function ($q) {
                $q->where('i.purchased', true)
                    ->orWhere('i.status', 'done');
            })
            ->whereBetween('i.updated_at', [$from, $to])
            ->select([
                DB::raw('DATE(i.updated_at) as sale_date'),
                'i.name',
                DB::raw('SUM(i.qty) as qty'),
                DB::raw('SUM(i.amount) as amount'),
                DB::raw('COUNT(DISTINCT i.visit_bill_id) as bills_count'),
            ])
            ->groupBy(DB::raw('DATE(i.updated_at)'), 'i.name')
            ->orderByDesc(DB::raw('DATE(i.updated_at)'))
            ->orderBy('i.name')
            ->get();

        $byDateMap = [];
        $byMedicineMap = [];

        foreach ($rows as $row) {
            $date = (string) $row->sale_date;
            $name = (string) $row->name;
            $qty = (int) $row->qty;
            $amount = round((float) $row->amount, 2);
            $bills = (int) $row->bills_count;

            if (! isset($byDateMap[$date])) {
                $byDateMap[$date] = [
                    'date' => $date,
                    'total_qty' => 0,
                    'total_amount' => 0.0,
                    'bills_count' => 0,
                    'medicines' => [],
                ];
            }
            $byDateMap[$date]['total_qty'] += $qty;
            $byDateMap[$date]['total_amount'] = round($byDateMap[$date]['total_amount'] + $amount, 2);
            $byDateMap[$date]['medicines'][] = [
                'name' => $name,
                'qty' => $qty,
                'amount' => $amount,
                'bills_count' => $bills,
            ];

            if (! isset($byMedicineMap[$name])) {
                $byMedicineMap[$name] = [
                    'name' => $name,
                    'qty' => 0,
                    'amount' => 0.0,
                    'bills_count' => 0,
                    'days_sold' => 0,
                ];
            }
            $byMedicineMap[$name]['qty'] += $qty;
            $byMedicineMap[$name]['amount'] = round($byMedicineMap[$name]['amount'] + $amount, 2);
            $byMedicineMap[$name]['bills_count'] += $bills;
            $byMedicineMap[$name]['days_sold'] += 1;
        }

        // Accurate distinct bill counts per day (rows above sum per-medicine bill counts).
        $dayBillCounts = VisitBillItem::query()
            ->from('visit_bill_items as i')
            ->where('i.section', 'pharmacy')
            ->where('i.included', true)
            ->where(function ($q) {
                $q->where('i.purchased', true)
                    ->orWhere('i.status', 'done');
            })
            ->whereBetween('i.updated_at', [$from, $to])
            ->select([
                DB::raw('DATE(i.updated_at) as sale_date'),
                DB::raw('COUNT(DISTINCT i.visit_bill_id) as bills_count'),
            ])
            ->groupBy(DB::raw('DATE(i.updated_at)'))
            ->pluck('bills_count', 'sale_date');

        foreach ($byDateMap as $date => &$day) {
            $day['bills_count'] = (int) ($dayBillCounts[$date] ?? 0);
            $day['total_amount'] = round($day['total_amount'], 2);
        }
        unset($day);

        // Distinct bill counts overall + per medicine (dedupe across days).
        $medicineBillCounts = VisitBillItem::query()
            ->from('visit_bill_items as i')
            ->where('i.section', 'pharmacy')
            ->where('i.included', true)
            ->where(function ($q) {
                $q->where('i.purchased', true)
                    ->orWhere('i.status', 'done');
            })
            ->whereBetween('i.updated_at', [$from, $to])
            ->select([
                'i.name',
                DB::raw('COUNT(DISTINCT i.visit_bill_id) as bills_count'),
            ])
            ->groupBy('i.name')
            ->pluck('bills_count', 'name');

        foreach ($byMedicineMap as $name => &$med) {
            $med['bills_count'] = (int) ($medicineBillCounts[$name] ?? 0);
            $med['amount'] = round($med['amount'], 2);
        }
        unset($med);

        $totalBills = (int) VisitBillItem::query()
            ->from('visit_bill_items as i')
            ->where('i.section', 'pharmacy')
            ->where('i.included', true)
            ->where(function ($q) {
                $q->where('i.purchased', true)
                    ->orWhere('i.status', 'done');
            })
            ->whereBetween('i.updated_at', [$from, $to])
            ->selectRaw('COUNT(DISTINCT i.visit_bill_id) as aggregate')
            ->value('aggregate');

        $byDate = array_values($byDateMap);
        $byMedicine = array_values($byMedicineMap);
        usort($byMedicine, fn ($a, $b) => $b['qty'] <=> $a['qty'] ?: strcmp($a['name'], $b['name']));

        $totalQty = array_sum(array_column($byMedicine, 'qty'));
        $totalAmount = round(array_sum(array_column($byMedicine, 'amount')), 2);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'total_qty' => $totalQty,
                'total_amount' => $totalAmount,
                'bills_count' => $totalBills,
                'medicines_count' => count($byMedicine),
                'days_count' => count($byDate),
            ],
            'by_date' => $byDate,
            'by_medicine' => $byMedicine,
        ]);
    }
}
