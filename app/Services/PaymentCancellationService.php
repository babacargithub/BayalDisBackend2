<?php

namespace App\Services;

use App\Data\Payment\PaymentCancellationResultData;
use App\Exceptions\PaymentCancellationException;
use App\Models\Caisse;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\DailyCommission;
use App\Models\Payment;
use App\Services\Commission\DailyCommissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancels a payment that was recorded by mistake (wrong customer, wrong amount,
 * duplicate entry) while keeping every financial aggregate consistent.
 *
 * The payment is soft-cancelled (cancelled_at / cancelled_by / reason) and kept
 * in the database for audit. The Payment global scope then excludes it from all
 * sums automatically (invoice totals, commission recalculation, statistics).
 *
 * Two scenarios are handled:
 *
 * 1. Same-day cancellation — the commercial's day has NOT been closed yet
 *    (no finalized DailyCommission, no versement). The cash is still in the
 *    commercial's caisse / COMMERCIAL_COLLECTED account, so the original credit
 *    is simply reversed and the daily commission is recalculated by the job
 *    dispatched from the Payment::updated event.
 *
 * 2. After-day-close cancellation — "Clôturer Journée" and/or a versement has
 *    already run: the COMMERCIAL_COLLECTED balance was swept to MERCHANDISE_SALES,
 *    the commission was credited to the commercial's COMMERCIAL_COMMISSION account,
 *    and (when a versement happened) the physical cash moved to the main caisse.
 *    Reversal then performs, in order:
 *      a. Recompute the DailyCommission for that work day without the cancelled
 *         payment, giving the commission overpaid to the commercial (the delta).
 *      b. Claw back the delta: COMMERCIAL_COMMISSION → MERCHANDISE_SALES.
 *      c. Withdraw the payment amount from the caisse currently holding the cash
 *         (main caisse if versed, otherwise the commercial's caisse) and debit
 *         MERCHANDISE_SALES, since the amount was counted as merchandise revenue.
 *
 * Every step runs in a single DB transaction: if any account lacks the required
 * balance an InsufficientAccountBalanceException aborts the whole cancellation.
 */
readonly class PaymentCancellationService
{
    public function __construct(
        private AccountService $accountService,
        private DailyCommissionService $dailyCommissionService,
    ) {}

    /**
     * @throws PaymentCancellationException when the payment is already cancelled
     * @throws Throwable on any accounting failure (whole operation is rolled back)
     */
    public function cancelPayment(
        Payment $payment,
        int $cancelledByUserId,
        string $cancellationReason,
    ): PaymentCancellationResultData {
        if ($payment->isCancelled()) {
            throw new PaymentCancellationException('Ce paiement a déjà été annulé.');
        }

        $commercial = $payment->user_id !== null
            ? Commercial::where('user_id', $payment->user_id)->first()
            : null;

        $workDay = $payment->created_at->toDateString();
        $workPeriod = $this->findWorkPeriodCoveringDay($commercial, $workDay);
        $dailyCommission = $this->findDailyCommissionForDay($workPeriod, $workDay);

        $dayWasAlreadyClosedOrVersed = $dailyCommission !== null
            && ($dailyCommission->finalized_at !== null || $dailyCommission->versement_id !== null);

        return DB::transaction(function () use (
            $payment,
            $cancelledByUserId,
            $cancellationReason,
            $commercial,
            $workPeriod,
            $workDay,
            $dailyCommission,
            $dayWasAlreadyClosedOrVersed,
        ): PaymentCancellationResultData {
            $netCommissionBeforeCancellation = $dailyCommission?->net_commission ?? 0;

            // Marking the payment cancelled fires Payment::updated, which recalculates
            // the invoice stored totals and dispatches the daily commission job.
            // The global scope excludes the payment from every aggregate from now on.
            $payment->forceFill([
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $cancelledByUserId,
                'cancellation_reason' => $cancellationReason,
            ])->save();

            if ($commercial === null || $commercial->caisse === null) {
                return new PaymentCancellationResultData(
                    paymentId: $payment->id,
                    wasCancelledAfterDayClose: $dayWasAlreadyClosedOrVersed,
                    cashReversalAmount: 0,
                    cashReversalCaisseName: null,
                    commissionClawbackAmount: 0,
                );
            }

            if (! $dayWasAlreadyClosedOrVersed) {
                return $this->reverseSameDayPayment($payment, $commercial);
            }

            return $this->reversePaymentAfterDayClose(
                payment: $payment,
                commercial: $commercial,
                workPeriod: $workPeriod,
                workDay: $workDay,
                dailyCommission: $dailyCommission,
                netCommissionBeforeCancellation: $netCommissionBeforeCancellation,
            );
        });
    }

    /**
     * Scenario 1 — the day is still open: the cash sits in the commercial's caisse
     * and COMMERCIAL_COLLECTED account, exactly where the payment credited it.
     * The daily commission itself is recalculated by the job dispatched from
     * the Payment::updated event.
     */
    private function reverseSameDayPayment(
        Payment $payment,
        Commercial $commercial,
    ): PaymentCancellationResultData {
        $collectedAccount = $this->accountService->getOrCreateCommercialCollectedAccount($commercial);

        $this->accountService->withdrawFromCaisseAndDebitAccount(
            caisse: $commercial->caisse,
            account: $collectedAccount,
            amount: $payment->amount,
            caisseLabel: "Annulation paiement #{$payment->id} — Facture #{$payment->sales_invoice_id}",
            accountLabel: "Annulation paiement #{$payment->id} — Facture #{$payment->sales_invoice_id}",
            referenceType: 'PAYMENT_CANCELLATION',
            referenceId: $payment->id,
        );

        return new PaymentCancellationResultData(
            paymentId: $payment->id,
            wasCancelledAfterDayClose: false,
            cashReversalAmount: $payment->amount,
            cashReversalCaisseName: $commercial->caisse->name,
            commissionClawbackAmount: 0,
        );
    }

    /**
     * Scenario 2 — the day was closed and/or versed: the collected cash was already
     * counted as MERCHANDISE_SALES revenue and the commission credited to the
     * commercial. Recompute the commission without the cancelled payment, claw back
     * the overpaid difference, then withdraw the phantom cash from the caisse that
     * currently holds it.
     *
     * @throws Throwable
     */
    private function reversePaymentAfterDayClose(
        Payment $payment,
        Commercial $commercial,
        CommercialWorkPeriod $workPeriod,
        string $workDay,
        DailyCommission $dailyCommission,
        int $netCommissionBeforeCancellation,
    ): PaymentCancellationResultData {
        $merchandiseSalesAccount = $this->accountService->getMerchandiseSalesAccount();

        // a. Recompute the day's commission without the cancelled payment.
        //    Called directly (not via the job) because the job skips finalized periods.
        $recalculatedDailyCommission = $this->dailyCommissionService->recalculateDailyCommissionForWorkDay(
            $commercial,
            $workPeriod,
            $workDay,
        );

        $overpaidCommissionDelta = max(
            0,
            $netCommissionBeforeCancellation - $recalculatedDailyCommission->net_commission,
        );

        // b. Claw back the overpaid commission from the commercial's commission account.
        //    Capped at the account balance: the commercial may already have withdrawn it.
        $commissionClawbackAmount = 0;

        if ($overpaidCommissionDelta > 0) {
            $commissionAccount = $this->accountService->getOrCreateCommercialCommissionAccount($commercial);
            $commissionAccount->refresh();
            $commissionClawbackAmount = min($overpaidCommissionDelta, $commissionAccount->balance);

            if ($commissionClawbackAmount > 0) {
                $this->accountService->transferBetweenAccounts(
                    fromAccount: $commissionAccount,
                    toAccount: $merchandiseSalesAccount,
                    amount: $commissionClawbackAmount,
                    label: "Reprise commission — annulation paiement #{$payment->id}",
                    referenceType: 'PAYMENT_CANCELLATION',
                    referenceId: $payment->id,
                );
            }

            if ($commissionClawbackAmount < $overpaidCommissionDelta) {
                Log::warning(
                    "Annulation paiement #{$payment->id} : commission trop-perçue de {$overpaidCommissionDelta} F "
                    ."mais seulement {$commissionClawbackAmount} F récupérés sur le compte commission de «{$commercial->name}». "
                    .'Réconciliation manuelle nécessaire pour le reste.',
                    [
                        'payment_id' => $payment->id,
                        'commercial_id' => $commercial->id,
                        'overpaid_commission' => $overpaidCommissionDelta,
                        'clawed_back' => $commissionClawbackAmount,
                    ]
                );
            }
        }

        // c. Withdraw the phantom cash. After a versement the physical cash is in the
        //    main caisse; before the versement it is still in the commercial's caisse.
        //    Either way the account side was already swept to MERCHANDISE_SALES.
        $caisseHoldingTheCash = $this->resolveCaisseHoldingTheCash($commercial, $dailyCommission);

        $this->accountService->withdrawFromCaisseAndDebitAccount(
            caisse: $caisseHoldingTheCash,
            account: $merchandiseSalesAccount,
            amount: $payment->amount,
            caisseLabel: "Annulation paiement #{$payment->id} — Facture #{$payment->sales_invoice_id}",
            accountLabel: "Annulation paiement #{$payment->id} — Facture #{$payment->sales_invoice_id}",
            referenceType: 'PAYMENT_CANCELLATION',
            referenceId: $payment->id,
        );

        return new PaymentCancellationResultData(
            paymentId: $payment->id,
            wasCancelledAfterDayClose: true,
            cashReversalAmount: $payment->amount,
            cashReversalCaisseName: $caisseHoldingTheCash->name,
            commissionClawbackAmount: $commissionClawbackAmount,
        );
    }

    private function resolveCaisseHoldingTheCash(
        Commercial $commercial,
        DailyCommission $dailyCommission,
    ): Caisse {
        if ($dailyCommission->versement_id !== null) {
            $mainCaisse = $dailyCommission->versement?->mainCaisse;

            if ($mainCaisse !== null) {
                return $mainCaisse;
            }
        }

        return $commercial->caisse;
    }

    private function findWorkPeriodCoveringDay(
        ?Commercial $commercial,
        string $workDay,
    ): ?CommercialWorkPeriod {
        if ($commercial === null) {
            return null;
        }

        return CommercialWorkPeriod::where('commercial_id', $commercial->id)
            ->whereDate('period_start_date', '<=', $workDay)
            ->whereDate('period_end_date', '>=', $workDay)
            ->first();
    }

    private function findDailyCommissionForDay(
        ?CommercialWorkPeriod $workPeriod,
        string $workDay,
    ): ?DailyCommission {
        if ($workPeriod === null) {
            return null;
        }

        return DailyCommission::where('commercial_work_period_id', $workPeriod->id)
            ->whereDate('work_day', $workDay)
            ->first();
    }
}
