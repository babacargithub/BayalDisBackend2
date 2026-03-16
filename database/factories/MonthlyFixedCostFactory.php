<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MonthlyFixedCost>
 */
class MonthlyFixedCostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cost_pool' => \App\Enums\MonthlyFixedCostPool::Storage,
            'sub_category' => \App\Enums\MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => $this->faker->numberBetween(50_000, 500_000),
            'period_year' => now()->year,
            'period_month' => now()->month,
            'per_vehicle_amount' => null,
            'active_vehicle_count' => null,
            'finalized_at' => null,
            'notes' => null,
        ];
    }
}
