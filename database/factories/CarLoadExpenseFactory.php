<?php

namespace Database\Factories;

use App\Enums\CarLoadExpenseType;
use App\Models\CarLoad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CarLoadExpense>
 */
class CarLoadExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'car_load_id' => CarLoad::factory(),
            'label' => null,
            'amount' => $this->faker->numberBetween(5_000, 80_000),
            'type' => CarLoadExpenseType::Fuel,
        ];
    }

    public function fuel(): static
    {
        return $this->state([
            'type' => CarLoadExpenseType::Fuel,
            'amount' => $this->faker->numberBetween(10_000, 80_000),
        ]);
    }

    public function parking(): static
    {
        return $this->state([
            'type' => CarLoadExpenseType::Parking,
            'label' => 'Parking',
            'amount' => $this->faker->numberBetween(500, 5_000),
        ]);
    }
}
