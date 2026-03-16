<?php
/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */

namespace App\Services\Commission;

use App\Data\Commission\CommissionPeriodData;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\Commission;
use App\Models\CommissionPaymentLine;
use App\Models\CommissionPeriodSetting;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Computes or refreshes the full commission for a commercial over a given period.
 *
 * Periods are expressed as a CommissionPeriodData (start + end date), which
 * supports both weekly (Mon → Sat) and monthly (1st → last day) frequencies.
 *
 * Calculation steps:
 *   1. Find or create the CommercialWorkPeriod for (the commercial, start, end).
 *   2. Collect all payments attributed to the commercial within the period dates.
 *   3. For each payment, allocate the amount across invoice products and apply
 *      the per-product commission rate → base_commission.
 *   4. Check for basket achievement (sold all required categories) → basket_bonus.
 *   5. Sum total encaissement and find the highest achieved objective tier → objective_bonus.
 *   6. Sum all penalties for the period → total_penalties.
 *   7. Net_commission = base + basket + objective − penalties (minimum 0).
 *   8. Persist (or update) the Commission record and its payment lines.
 *
 * A finalized commission cannot be recomputed. Call finalizeCommission() to lock it.
 */
readonly class CommercialWorkPeriodService
{
    public function __construct(
        private CommissionCalculatorService $commissionCalculatorService,
    ) {}

    /**
     * Compute (or re-compute) the commission for a commercial over the given period.
     * Throws RuntimeException if the commission for this period is already finalized,
     * or if the requested period overlaps with a different existing work period.
     *
     * @throws RuntimeException|Throwable
     */
    public function computeOrRefreshCommissionForPeriod(
        Commercial $commercial,
        CommissionPeriodData $period,
    ): Commission {
        // Look for an existing work period covering the exact same dates.
        $existingWorkPeriod = CommercialWorkPeriod::where('commercial_id', $commercial->id)
            ->where('period_start_date', $period->startDate->startOfDay())
            ->where('period_end_date', $period->endDate->startOfDay())
            ->first();

        $existingCommission = $existingWorkPeriod?->commission;

        if ($existingCommission !== null && $existingCommission->is_finalized) {
            throw new RuntimeException(
                "Commission for commercial #{$commercial->id} period {$period->label()} "
                .'is already finalized and cannot be recomputed.'
            );
        }

        // Only check for overlap when this is a new period (not a refresh of an existing one).
        if ($existingWorkPeriod === null
            && CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercial->id, $period)) {
            throw new RuntimeException(
                "Commission for commercial #{$commercial->id} period {$period->label()} "
                .'overlaps with an existing commission period. Periods must not overlap.'
            );
        }

        return DB::transaction(function () use ($commercial, $period, $existingWorkPeriod, $existingCommission) {
            // Create the work period if it does not yet exist.
            $workPeriod = $existingWorkPeriod ?? CommercialWorkPeriod::create([
                'commercial_id' => $commercial->id,
                'period_start_date' => $period->startDate->startOfDay(),
                'period_end_date' => $period->endDate->startOfDay(),
            ]);

            // Delete existing payment lines so we can recompute from scratch.
            $existingCommission?->paymentLines()->delete();

            $paymentsInPeriod = $commercial->payments()
                ->whereBetween('created_at', [
                    $period->startDate->startOfDay(),
                    $period->endDate->endOfDay(),
                ])
                ->with('salesInvoice')
                ->get();

            $baseCommission = 0;
            $soldCategoryIds = [];
            $allPaymentLines = [];

            foreach ($paymentsInPeriod as $payment) {
                /** @var Payment $payment */
                $paymentLines = $this->commissionCalculatorService
                    ->computePaymentLinesForCommercial($payment, $commercial);

                foreach ($paymentLines as $paymentLineData) {
                    $baseCommission += $paymentLineData->commissionAmount;
                    $allPaymentLines[] = $paymentLineData;

                    // Collect product category IDs to check basket achievement later.
                    $productCategoryId = Product::find($paymentLineData->productId)?->product_category_id;

                    if ($productCategoryId !== null) {
                        $soldCategoryIds[$productCategoryId] = true;
                    }
                }
            }

            // --- Basket bonus ---
            $periodSetting = CommissionPeriodSetting::forPeriod($period);

            $basketAchieved = false;
            $basketBonus = 0;
            $basketMultiplierApplied = null;

            if ($periodSetting !== null && ! empty($periodSetting->required_category_ids)) {
                $allRequiredCategoriesSold = collect($periodSetting->required_category_ids)
                    ->every(fn (int $categoryId) => isset($soldCategoryIds[$categoryId]));

                if ($allRequiredCategoriesSold) {
                    $basketAchieved = true;
                    $basketMultiplierApplied = (float) $periodSetting->basket_multiplier;
                    // basket_bonus = base_commission × (multiplier − 1), rounded to integer XOF.
                    $basketBonus = (int) round($baseCommission * ($basketMultiplierApplied - 1));
                }
            }

            // --- Objective bonus (non-cumulative: only the highest achieved tier pays out) ---
            $totalEncaissementInPeriod = $paymentsInPeriod->sum('amount');

            $highestAchievedTier = $workPeriod->objectiveTiers()
                ->where('ca_threshold', '<=', $totalEncaissementInPeriod)
                ->orderByDesc('tier_level')
                ->first();

            $objectiveBonus = $highestAchievedTier?->bonus_amount ?? 0;
            $achievedTierLevel = $highestAchievedTier?->tier_level;

            // --- Penalties ---
            $totalPenalties = $workPeriod->penalties()->sum('amount');

            $netCommission = max(0, $baseCommission + $basketBonus + $objectiveBonus - $totalPenalties);

            // --- Persist Commission ---
            $commissionRecord = Commission::updateOrCreate(
                ['commercial_work_period_id' => $workPeriod->id],
                [
                    'base_commission' => $baseCommission,
                    'basket_bonus' => $basketBonus,
                    'objective_bonus' => $objectiveBonus,
                    'total_penalties' => $totalPenalties,
                    'net_commission' => $netCommission,
                    'basket_achieved' => $basketAchieved,
                    'basket_multiplier_applied' => $basketMultiplierApplied,
                    'achieved_tier_level' => $achievedTierLevel,
                ]
            );

            // --- Persist payment lines ---
            foreach ($allPaymentLines as $paymentLineData) {
                CommissionPaymentLine::create([
                    'commission_id' => $commissionRecord->id,
                    'payment_id' => $paymentLineData->paymentId,
                    'product_id' => $paymentLineData->productId,
                    'rate_applied' => $paymentLineData->rateApplied,
                    'payment_amount_allocated' => $paymentLineData->paymentAmountAllocated,
                    'commission_amount' => $paymentLineData->commissionAmount,
                ]);
            }

            return $commissionRecord->fresh();
        });
    }

    /**
     * Lock a commission record so it cannot be recomputed.
     * Throws RuntimeException if it is already finalized.
     *
     * @throws RuntimeException
     */
    public function finalizeCommission(Commission $commission): Commission
    {
        if ($commission->is_finalized) {
            throw new RuntimeException(
                "Commission #{$commission->id} is already finalized."
            );
        }

        $commission->update([
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);

        return $commission->fresh();
    }
}
