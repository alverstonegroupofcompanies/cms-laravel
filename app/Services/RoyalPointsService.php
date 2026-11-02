<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\RoyalPointTransaction;
use App\Models\VisitBill;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RoyalPointsService
{
    /** Rate: 4 points per 100 rupees spent */
    public const EARN_POINTS_PER_100_RS = 4;

    /** Rate: 4 points = 1 rupee discount */
    public const REDEEM_POINTS_PER_1_RS = 4;

    /** Calculate points earned for a given amount paid */
    public function calculatePointsEarned(float $paidAmount): int
    {
        if ($paidAmount < 100) {
            return 0;
        }

        return (int) (floor($paidAmount / 100) * self::EARN_POINTS_PER_100_RS);
    }

    /** Convert points to rupee value (4 pt = 1 rs) */
    public function pointsToRupees(int $points): float
    {
        return round($points / self::REDEEM_POINTS_PER_1_RS, 2);
    }

    /** Convert rupee amount to points needed */
    public function rupeesToPoints(float $rupees): int
    {
        return (int) ceil($rupees * self::REDEEM_POINTS_PER_1_RS);
    }

    /**
     * Apply or update reward points redeemed against a visit bill.
     */
    public function applyRedemption(VisitBill $bill, int $pointsToRedeem): VisitBill
    {
        return DB::transaction(function () use ($bill, $pointsToRedeem) {
            $bill->loadMissing('patient');
            $patient = $bill->patient;
            if (! $patient) {
                throw new InvalidArgumentException('Bill does not have a linked patient.');
            }

            // Restore any previously redeemed points on this bill first
            $previousRedeemed = (int) ($bill->points_redeemed ?? 0);
            if ($previousRedeemed > 0) {
                $patient->increment('royal_points', $previousRedeemed);
                RoyalPointTransaction::create([
                    'patient_id' => $patient->id,
                    'visit_bill_id' => $bill->id,
                    'points' => $previousRedeemed,
                    'type' => 'refund',
                    'description' => "Restored {$previousRedeemed} points from updated bill #{$bill->bill_code}",
                ]);
            }

            // Re-read patient available points
            $patient->refresh();
            $available = (int) $patient->royal_points;

            // Maximum points that can be redeemed on this bill (cannot exceed grand total)
            $maxPointsForBill = (int) floor(((float) $bill->grand_total) * self::REDEEM_POINTS_PER_1_RS);
            $points = max(0, min($pointsToRedeem, $available, $maxPointsForBill));
            $discount = $this->pointsToRupees($points);

            if ($points > 0) {
                $patient->decrement('royal_points', $points);

                RoyalPointTransaction::create([
                    'patient_id' => $patient->id,
                    'visit_bill_id' => $bill->id,
                    'points' => -$points,
                    'type' => 'redeemed',
                    'description' => "Redeemed {$points} Royal Points (-₹{$discount}) on bill #{$bill->bill_code}",
                ]);
            }

            $bill->update([
                'points_redeemed' => $points,
                'points_discount' => $discount,
            ]);

            return $bill->fresh()->load(['items', 'patient', 'doctor', 'worksheet', 'queueToken']);
        });
    }

    /**
     * Credit earned Royal Points when a bill is paid / completed.
     */
    public function creditEarnedPointsOnCompletion(VisitBill $bill): int
    {
        // Only credit once when bill is completed
        if ($bill->status !== 'completed' || ((int) ($bill->points_earned ?? 0)) > 0) {
            return 0;
        }

        return DB::transaction(function () use ($bill) {
            $bill->loadMissing('patient');
            $patient = $bill->patient;
            if (! $patient) {
                return 0;
            }

            $netPaid = max(0.0, ((float) $bill->grand_total) - ((float) ($bill->points_discount ?? 0)));
            $earned = $this->calculatePointsEarned($netPaid);

            if ($earned > 0) {
                $bill->update(['points_earned' => $earned]);
                $patient->increment('royal_points', $earned);

                RoyalPointTransaction::create([
                    'patient_id' => $patient->id,
                    'visit_bill_id' => $bill->id,
                    'points' => $earned,
                    'type' => 'earned',
                    'description' => "Earned {$earned} Royal Points for ₹{$netPaid} paid on bill #{$bill->bill_code}",
                ]);
            }

            return $earned;
        });
    }
}

