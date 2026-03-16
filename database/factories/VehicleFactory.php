<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Camion '.$this->faker->randomElement(['Alpha', 'Beta', 'Gamma', 'Delta']),
            'plate_number' => strtoupper($this->faker->bothify('??-###-??')),
            'insurance_monthly' => $this->faker->numberBetween(30_000, 80_000),
            'maintenance_monthly' => $this->faker->numberBetween(20_000, 60_000),
            'repair_reserve_monthly' => $this->faker->numberBetween(10_000, 30_000),
            'depreciation_monthly' => $this->faker->numberBetween(20_000, 50_000),
            'driver_salary_monthly' => $this->faker->numberBetween(80_000, 150_000),
            'working_days_per_month' => 26,
            'notes' => null,
        ];
    }
}
