<?php

namespace Tests\Feature\Account;

use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\Commercial;
use App\Models\CommercialVersement;
use App\Models\CommercialWorkPeriod;
use App\Models\DailyCommission;
use App\Services\AccountService;
use App\Services\VersementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersementTest extends TestCase
{
    use RefreshDatabase;

    private VersementService $versementService;

    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versementService = app(VersementService::class);
        $this->accountService = app(AccountService::class);
    }

    private function assertGlobalInvariantHolds(): void
    {
        $totalAccounts = Account::sum('balance');
        $totalCaisses = Caisse::sum('balance');
        $this->assertSame($totalCaisses, $totalAccounts,
            "Invariant violated: accounts={$totalAccounts} ≠ caisses={$totalCaisses}");
    }

    /**
     * Create a commercial with a caisse and the required accounts (bypassing the booted hook
     * to have full control over balances in tests).
     */
    private function createCommercialWithCaisse(int $caisseBalance = 0): array
    {
        $commercial = Commercial::factory()->create();

        // The booted hook creates the caisse and accounts automatically.
        // Manually update caisse balance for test scenarios.
        $commercial->caisse->update(['balance' => $caisseBalance]);

        // Also set the COMMERCIAL_COLLECTED account to match caisse balance
        $collectedAccount = Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();
        $collectedAccount->update(['balance' => $caisseBalance]);

        return [$commercial, $commercial->caisse, $collectedAccount];
    }

    private function createMainCaisse(int $balance = 0): Caisse
    {
        return Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => $balance,
            'closed' => false,
        ]);
    }

    private function createMerchandiseSalesAccount(int $balance = 0): Account
    {
        return Account::create([
            'name' => 'Vente marchandises',
            'account_type' => AccountType::MerchandiseSales,
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    private function createUnversedDailyCommission(Commercial $commercial, int $netCommission): DailyCommission
    {
        $workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $commercial->id,
            'period_start_date' => today()->startOfWeek()->toDateString(),
            'period_end_date' => today()->endOfWeek()->toDateString(),
            'is_finalized' => false,
        ]);

        return DailyCommission::create([
            'commercial_work_period_id' => $workPeriod->id,
            'work_day' => today()->toDateString(),
            'base_commission' => $netCommission,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'new_confirmed_customers_bonus' => 0,
            'new_prospect_customers_bonus' => 0,
            'net_commission' => $netCommission,
            'basket_achieved' => false,
        ]);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_versement_sweeps_full_commercial_caisse_balance_to_zero(): void
    {
        [$commercial, $commercialCaisse] = $this->createCommercialWithCaisse(100_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);

        $this->versementService->performVersement($commercial, $mainCaisse);

        $commercialCaisse->refresh();
        $this->assertSame(0, $commercialCaisse->balance);
    }

    public function test_versement_increases_main_caisse_by_exact_versement_amount(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(150_000);
        $mainCaisse = $this->createMainCaisse(50_000);
        $this->createMerchandiseSalesAccount(0);

        $this->versementService->performVersement($commercial, $mainCaisse);

        $mainCaisse->refresh();
        $this->assertSame(200_000, $mainCaisse->balance);
    }

    public function test_versement_credits_commercial_commission_account_with_earned_commission(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(200_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        $this->createUnversedDailyCommission($commercial, 15_000);

        $this->versementService->performVersement($commercial, $mainCaisse);

        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();

        $this->assertSame(15_000, $commissionAccount->balance);
    }

    public function test_versement_credits_merchandise_sales_with_remainder_after_commission(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(200_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        $this->createUnversedDailyCommission($commercial, 15_000);

        $this->versementService->performVersement($commercial, $mainCaisse);

        $merchandiseSalesAccount = Account::where('account_type', AccountType::MerchandiseSales->value)->firstOrFail();
        $this->assertSame(185_000, $merchandiseSalesAccount->balance);
    }

    public function test_versement_with_zero_commission_credits_full_amount_to_merchandise_sales(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(80_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        // No DailyCommissions — no commission to pay

        $this->versementService->performVersement($commercial, $mainCaisse);

        $merchandiseSalesAccount = Account::where('account_type', AccountType::MerchandiseSales->value)->firstOrFail();
        $this->assertSame(80_000, $merchandiseSalesAccount->balance);

        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)->firstOrFail();
        $this->assertSame(0, $commissionAccount->balance);
    }

    public function test_versement_creates_a_commercial_versement_record(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(60_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        $this->createUnversedDailyCommission($commercial, 5_000);

        $versement = $this->versementService->performVersement($commercial, $mainCaisse);

        $this->assertInstanceOf(CommercialVersement::class, $versement);
        $this->assertSame($commercial->id, $versement->commercial_id);
        $this->assertSame($mainCaisse->id, $versement->main_caisse_id);
        $this->assertSame(60_000, $versement->amount_versed);
        $this->assertSame(5_000, $versement->commission_credited);
        $this->assertSame(55_000, $versement->merchandise_credited);
    }

    public function test_versement_creates_caisse_withdraw_transaction_on_commercial_caisse(): void
    {
        [$commercial, $commercialCaisse] = $this->createCommercialWithCaisse(70_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);

        $this->versementService->performVersement($commercial, $mainCaisse);

        $this->assertDatabaseHas('caisse_transactions', [
            'caisse_id' => $commercialCaisse->id,
            'amount' => 70_000,
            'transaction_type' => 'WITHDRAW',
        ]);
    }

    public function test_versement_creates_caisse_deposit_transaction_on_main_caisse(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(70_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);

        $this->versementService->performVersement($commercial, $mainCaisse);

        $this->assertDatabaseHas('caisse_transactions', [
            'caisse_id' => $mainCaisse->id,
            'amount' => 70_000,
            'transaction_type' => 'DEPOSIT',
        ]);
    }

    public function test_versement_marks_daily_commissions_as_versed(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(100_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        $dailyCommission = $this->createUnversedDailyCommission($commercial, 10_000);

        $versement = $this->versementService->performVersement($commercial, $mainCaisse);

        $dailyCommission->refresh();
        $this->assertSame($versement->id, $dailyCommission->versement_id);
    }

    public function test_versement_only_marks_unversed_daily_commissions(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(200_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);

        // First versement — creates one DailyCommission and verses it
        $firstDailyCommission = $this->createUnversedDailyCommission($commercial, 8_000);
        $firstVersement = $this->versementService->performVersement($commercial, $mainCaisse);

        // Reset caisse for second versement
        $commercial->caisse->update(['balance' => 100_000]);
        Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->update(['balance' => 100_000]);

        // New DailyCommission for the next day
        $workPeriod = $commercial->workPeriods()->first();
        $secondDailyCommission = DailyCommission::create([
            'commercial_work_period_id' => $workPeriod->id,
            'work_day' => today()->addDay()->toDateString(),
            'base_commission' => 6_000, 'basket_bonus' => 0, 'objective_bonus' => 0,
            'total_penalties' => 0, 'new_confirmed_customers_bonus' => 0,
            'new_prospect_customers_bonus' => 0, 'net_commission' => 6_000, 'basket_achieved' => false,
        ]);

        $secondVersement = $this->versementService->performVersement($commercial, $mainCaisse);

        $firstDailyCommission->refresh();
        $secondDailyCommission->refresh();

        $this->assertSame($firstVersement->id, $firstDailyCommission->versement_id);
        $this->assertSame($secondVersement->id, $secondDailyCommission->versement_id);
        $this->assertSame(8_000, $firstVersement->commission_credited);
        $this->assertSame(6_000, $secondVersement->commission_credited);
    }

    public function test_versement_preserves_the_global_invariant(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(180_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        $this->createUnversedDailyCommission($commercial, 12_000);

        // Invariant before (commercial caisse 180k, collected account 180k, main caisse 0, others 0)
        $this->assertSame(
            Caisse::sum('balance'),
            Account::sum('balance'),
        );

        $this->versementService->performVersement($commercial, $mainCaisse);

        $this->assertSame(
            Caisse::sum('balance'),
            Account::sum('balance'),
            'Global invariant violated after versement'
        );
    }

    public function test_versement_sum_commission_plus_merchandise_equals_amount_versed(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(90_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        $this->createUnversedDailyCommission($commercial, 9_000);

        $versement = $this->versementService->performVersement($commercial, $mainCaisse);

        $this->assertSame(
            $versement->amount_versed,
            $versement->commission_credited + $versement->merchandise_credited
        );
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function test_versement_throws_when_commercial_caisse_is_empty(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(0);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);

        $this->expectException(\RuntimeException::class);
        $this->versementService->performVersement($commercial, $mainCaisse);
    }

    public function test_versement_throws_when_commercial_has_no_caisse(): void
    {
        // Create commercial without triggering the booted hook (to skip caisse creation)
        $commercial = Commercial::factory()->create();
        $commercial->caisse->delete(); // Remove the auto-created caisse

        $mainCaisse = $this->createMainCaisse(0);

        $this->expectException(\RuntimeException::class);
        $this->versementService->performVersement($commercial, $mainCaisse);
    }

    public function test_versement_caps_commission_at_amount_versed_when_commission_exceeds_cash(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(3_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);
        $this->createUnversedDailyCommission($commercial, 10_000); // commission > cash

        $versement = $this->versementService->performVersement($commercial, $mainCaisse);

        $this->assertSame(3_000, $versement->commission_credited);
        $this->assertSame(0, $versement->merchandise_credited);
        $this->assertSame(3_000, $versement->amount_versed);
    }

    public function test_multiple_unversed_daily_commissions_are_summed_correctly(): void
    {
        [$commercial] = $this->createCommercialWithCaisse(300_000);
        $mainCaisse = $this->createMainCaisse(0);
        $this->createMerchandiseSalesAccount(0);

        $workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $commercial->id,
            'period_start_date' => today()->startOfWeek()->toDateString(),
            'period_end_date' => today()->endOfWeek()->toDateString(),
            'is_finalized' => false,
        ]);

        // Create 3 unversed days with different commissions
        foreach ([10_000, 15_000, 8_000] as $index => $netCommission) {
            DailyCommission::create([
                'commercial_work_period_id' => $workPeriod->id,
                'work_day' => today()->subDays($index)->toDateString(),
                'base_commission' => $netCommission, 'basket_bonus' => 0, 'objective_bonus' => 0,
                'total_penalties' => 0, 'new_confirmed_customers_bonus' => 0,
                'new_prospect_customers_bonus' => 0, 'net_commission' => $netCommission, 'basket_achieved' => false,
            ]);
        }

        $versement = $this->versementService->performVersement($commercial, $mainCaisse);

        $this->assertSame(33_000, $versement->commission_credited);
        $this->assertSame(267_000, $versement->merchandise_credited);
    }
}
