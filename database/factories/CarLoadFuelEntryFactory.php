<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CarLoadFuelEntry>
 */
class CarLoadFuelEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'car_load_id' => \App\Models\CarLoad::factory(),
            'amount' => $this->faker->numberBetween(10_000, 80_000),
            'liters' => $this->faker->randomFloat(2, 10, 80),
            'filled_at' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'notes' => null,
        ];
    }
}
