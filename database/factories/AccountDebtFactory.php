<?php

namespace Database\Factories;

use App\Enums\AccountDebtStatus;
use App\Models\Account;
use App\Models\AccountDebt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountDebt>
 */
class AccountDebtFactory extends Factory
{
    protected $model = AccountDebt::class;

    public function definition(): array
    {
        $originalAmount = $this->faker->numberBetween(10_000, 500_000);

        return [
            'debtor_account_id' => Account::factory(),
            'creditor_account_id' => Account::factory(),
            'original_amount' => $originalAmount,
            'remaining_amount' => $originalAmount,
            'status' => AccountDebtStatus::Pending,
            'reason' => $this->faker->sentence(),
        ];
    }

    public function fullyRepaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'remaining_amount' => 0,
            'status' => AccountDebtStatus::FullyRepaid,
        ]);
    }

    public function partiallyRepaid(int $remainingAmount): static
    {
        return $this->state(fn (array $attributes) => [
            'remaining_amount' => $remainingAmount,
            'status' => AccountDebtStatus::PartiallyRepaid,
        ]);
    }
}
