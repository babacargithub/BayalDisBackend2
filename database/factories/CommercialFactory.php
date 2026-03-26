<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Commercial>
 */
class CommercialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone_number' => $this->faker->numerify('7########'),
            'gender' => $this->faker->randomElement(['M', 'F']),
            'salary' => $this->faker->numberBetween(80_000, 200_000),
            'secret_code' => \Illuminate\Support\Facades\Hash::make('1234'),
            'user_id' => null,
        ];
    }
}
