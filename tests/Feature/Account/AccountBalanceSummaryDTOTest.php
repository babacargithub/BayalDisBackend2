<?php

namespace Tests\Feature\Account;

use App\Data\Account\AccountBalanceSummaryDTO;
use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountBalanceSummaryDTOTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(AccountType $accountType, int $balance): Account
    {
        return Account::create([
            'name' => $accountType->label(),
            'account_type' => $accountType,
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    public function test_all_four_totals_are_computed_correctly_from_a_mixed_collection(): void
    {
        $accounts = collect([
            $this->makeAccount(AccountType::MerchandiseSales, 100_000),
            $this->makeAccount(AccountType::Profit, 50_000),
            $this->makeAccount(AccountType::CommercialCommission, 20_000),
            $this->makeAccount(AccountType::VehicleFuel, 15_000),
            $this->makeAccount(AccountType::FixedCost, 5_000),
        ]);

        $dto = AccountBalanceSummaryDTO::fromAccounts($accounts);

        $this->assertSame(100_000, $dto->merchandiseSalesBalance);
        $this->assertSame(50_000, $dto->profitAccountBalance);
        $this->assertSame(40_000, $dto->reservesBalance); // 20_000 + 15_000 + 5_000
        $this->assertSame(90_000, $dto->totalNonUtilisable); // 50_000 + 40_000
    }

    public function test_merchandise_balance_sums_all_merchandise_accounts(): void
    {
        $dto = AccountBalanceSummaryDTO::fromAccounts(collect([
            $this->makeAccount(AccountType::MerchandiseSales, 60_000),
            $this->makeAccount(AccountType::MerchandiseSales, 40_000),
        ]));

        $this->assertSame(100_000, $dto->merchandiseSalesBalance);
    }

    public function test_reserves_includes_all_non_merchandise_non_profit_account_types(): void
    {
        $accounts = collect([
            $this->makeAccount(AccountType::CommercialCommission, 1_000),
            $this->makeAccount(AccountType::CommercialCollected, 2_000),
            $this->makeAccount(AccountType::VehicleDepreciation, 3_000),
            $this->makeAccount(AccountType::VehicleInsurance, 4_000),
            $this->makeAccount(AccountType::VehicleRepairReserve, 5_000),
            $this->makeAccount(AccountType::VehicleMaintenance, 6_000),
            $this->makeAccount(AccountType::VehicleFuel, 7_000),
            $this->makeAccount(AccountType::VehicleDriverSalary, 8_000),
            $this->makeAccount(AccountType::FixedCost, 9_000),
        ]);

        $dto = AccountBalanceSummaryDTO::fromAccounts($accounts);

        $this->assertSame(0, $dto->merchandiseSalesBalance);
        $this->assertSame(0, $dto->profitAccountBalance);
        $this->assertSame(45_000, $dto->reservesBalance); // 1+2+3+4+5+6+7+8+9 = 45
        $this->assertSame(45_000, $dto->totalNonUtilisable);
    }

    public function test_all_totals_are_zero_when_collection_is_empty(): void
    {
        $dto = AccountBalanceSummaryDTO::fromAccounts(collect());

        $this->assertSame(0, $dto->merchandiseSalesBalance);
        $this->assertSame(0, $dto->profitAccountBalance);
        $this->assertSame(0, $dto->reservesBalance);
        $this->assertSame(0, $dto->totalNonUtilisable);
    }

    public function test_totals_when_only_merchandise_account_exists(): void
    {
        $dto = AccountBalanceSummaryDTO::fromAccounts(collect([
            $this->makeAccount(AccountType::MerchandiseSales, 200_000),
        ]));

        $this->assertSame(200_000, $dto->merchandiseSalesBalance);
        $this->assertSame(0, $dto->profitAccountBalance);
        $this->assertSame(0, $dto->reservesBalance);
        $this->assertSame(0, $dto->totalNonUtilisable);
    }

    public function test_total_non_utilisable_always_equals_profit_plus_reserves(): void
    {
        $dto = AccountBalanceSummaryDTO::fromAccounts(collect([
            $this->makeAccount(AccountType::MerchandiseSales, 500_000),
            $this->makeAccount(AccountType::Profit, 75_000),
            $this->makeAccount(AccountType::FixedCost, 25_000),
        ]));

        $this->assertSame(
            $dto->profitAccountBalance + $dto->reservesBalance,
            $dto->totalNonUtilisable
        );
    }

    public function test_to_array_returns_correct_snake_case_keys_and_values(): void
    {
        $dto = AccountBalanceSummaryDTO::fromAccounts(collect([
            $this->makeAccount(AccountType::MerchandiseSales, 10_000),
            $this->makeAccount(AccountType::Profit, 5_000),
            $this->makeAccount(AccountType::FixedCost, 3_000),
        ]));

        $array = $dto->toArray();

        $this->assertSame(10_000, $array['merchandise_sales_balance']);
        $this->assertSame(5_000, $array['profit_account_balance']);
        $this->assertSame(3_000, $array['reserves_balance']);
        $this->assertSame(8_000, $array['total_non_utilisable']);
    }
}
