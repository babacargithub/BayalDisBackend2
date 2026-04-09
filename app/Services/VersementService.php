<?php

namespace App\Services;

use App\Models\Caisse;
use App\Models\Commercial;
use App\Models\CommercialVersement;
use App\Models\DailyCommission;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

readonly class VersementService
{
    public function __construct(private AccountService $accountService) {}

    /**
     * Perform a full versement for a commercial:
     *
     *   1. Sweeps the commercial's entire caisse balance to the designated main caisse.
     *   2. Credits the commercial's COMMERCIAL_COMMISSION account with their
     *      net commission for all daily commissions not yet versed.
     *   3. Credits the MERCHANDISE_SALES account with the remainder.
     *
     * The company-wide invariant (SUM accounts == SUM caisses) is preserved:
     *   — Net caisse change : commercial caisse −X, main caisse +X  → net 0
     *   — Net account change: CommercialCollected −X, CommercialCommission +C,
     *                         MerchandiseSales +(X−C)              → net 0
     *
     * All writes are wrapped in a single DB transaction so the operation is
     * atomic — either everything succeeds or nothing is persisted.
     *
     * @throws RuntimeException|Throwable if the commercial has no caisse or the caisse is empty
     */
    public function performVersement(Commercial $commercial, Caisse $mainCaisse): CommercialVersement
    {
        $commercialCaisse = $commercial->caisse;

        if ($commercialCaisse === null) {
            throw new RuntimeException(
                "Le commercial «{$commercial->name}» n'a pas de caisse configurée."
            );
        }

        $commercialCaisse->refresh();
        $amountToVerse = $commercialCaisse->balance;

        if ($amountToVerse <= 0) {
            throw new RuntimeException(
                "La caisse du commercial «{$commercial->name}» est vide. Rien à verser."
            );
        }

        // Sum of net_commission for days that have not yet been included in a versement.
        $unversedDailyCommissions = DailyCommission::whereHas(
            'workPeriod',
            fn ($query) => $query->where('commercial_id', $commercial->id)
        )->whereNull('versement_id')->get();

        // Finalized commissions have already been transferred to COMMERCIAL_COMMISSION
        // by "Clôturer Journée" — do NOT credit them again here.
        // Non-finalized commissions are credited normally.
        $commissionToCredit = $unversedDailyCommissions
            ->whereNull('finalized_at')
            ->sum('net_commission');

        // Determine how much of the versement still needs account settlement.
        // "Clôturer Journée" drains COMMERCIAL_COLLECTED to zero and moves funds directly
        // to MERCHANDISE_SALES, so by versement time the collected account may be empty.
        // In that case the physical caisse sweep (step 1) is still performed, but account
        // reallocation (step 2) is skipped to avoid double-posting.
        $collectedAccount = $this->accountService->getOrCreateCommercialCollectedAccount($commercial);
        $collectedAccount->refresh();
        $amountToSettleInAccounts = min($collectedAccount->balance, $amountToVerse);

        // The commission owed can never exceed the cash being settled in accounts.
        // Cap it so merchandise_credited is never negative.
        $commissionToCredit = min($commissionToCredit, $amountToSettleInAccounts);
        $merchandiseToCredit = $amountToSettleInAccounts - $commissionToCredit;

        return DB::transaction(function () use (
            $commercial,
            $commercialCaisse,
            $mainCaisse,
            $amountToVerse,
            $amountToSettleInAccounts,
            $commissionToCredit,
            $merchandiseToCredit,
            $collectedAccount,
            $unversedDailyCommissions,
        ): CommercialVersement {

            // ── Step 1: Move cash between caisses ─────────────────────────────

            $caisseWithdrawTransaction = $commercialCaisse->transactions()->create([
                'amount' => $amountToVerse,
                'transaction_type' => Caisse::TRANSACTION_TYPE_WITHDRAW,
                'label' => "Versement — {$commercial->name}",
            ]);
            $commercialCaisse->updateBalanceFromLedger();
            $commercialCaisse->save();

            $caisseDepositTransaction = $mainCaisse->transactions()->create([
                'amount' => $amountToVerse,
                'transaction_type' => Caisse::TRANSACTION_TYPE_DEPOSIT,
                'label' => "Versement de {$commercial->name}",
            ]);
            $mainCaisse->updateBalanceFromLedger();
            $mainCaisse->save();

            // ── Step 2: Reallocate between accounts ───────────────────────────
            // Skipped when "Clôturer Journée" has already settled the accounts
            // (amountToSettleInAccounts = 0 because COMMERCIAL_COLLECTED was drained).

            $collectedAccountDebitTransaction = null;
            if ($amountToSettleInAccounts > 0) {
                $collectedAccountDebitTransaction = $this->accountService->debit(
                    account: $collectedAccount,
                    amount: $amountToSettleInAccounts,
                    label: "Versement — {$commercial->name}",
                    referenceType: 'VERSEMENT',
                );
            }

            // Credit the commission account with the earned net commission.
            $commissionAccountTransaction = null;
            if ($commissionToCredit > 0) {
                $commissionAccount = $this->accountService->getOrCreateCommercialCommissionAccount($commercial);
                $commissionAccountTransaction = $this->accountService->credit(
                    account: $commissionAccount,
                    amount: $commissionToCredit,
                    label: "Commission versement — {$commercial->name}",
                    referenceType: 'VERSEMENT',
                );
            }

            // Credit the merchandise sales account with the remainder.
            $merchandiseAccountTransaction = null;
            if ($merchandiseToCredit > 0) {
                $merchandiseSalesAccount = $this->accountService->getMerchandiseSalesAccount();
                $merchandiseAccountTransaction = $this->accountService->credit(
                    account: $merchandiseSalesAccount,
                    amount: $merchandiseToCredit,
                    label: "Vente marchandises — versement de {$commercial->name}",
                    referenceType: 'VERSEMENT du '.now()->format('d/m/Y'),
                );
            }

            // ── Step 3: Persist the versement record ──────────────────────────

            $versement = CommercialVersement::create([
                'commercial_id' => $commercial->id,
                'main_caisse_id' => $mainCaisse->id,
                'versement_date' => today()->toDateString(),
                'amount_versed' => $amountToVerse,
                'commission_credited' => $commissionToCredit,
                'merchandise_credited' => $merchandiseToCredit,
                'caisse_withdraw_transaction_id' => $caisseWithdrawTransaction->id,
                'caisse_deposit_transaction_id' => $caisseDepositTransaction->id,
                'collected_account_debit_transaction_id' => $collectedAccountDebitTransaction?->id,
                'commission_account_transaction_id' => $commissionAccountTransaction?->id,
                'merchandise_account_transaction_id' => $merchandiseAccountTransaction?->id,
            ]);

            // ── Step 4: Mark daily commissions as versed ──────────────────────

            DailyCommission::whereIn('id', $unversedDailyCommissions->pluck('id'))
                ->update(['versement_id' => $versement->id]);

            // ── Invariant guard ────────────────────────────────────────────────
            $this->accountService->assertGlobalInvariantHolds();

            return $versement;
        });
    }
}
