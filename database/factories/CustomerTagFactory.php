<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerTag>
 */
class CustomerTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'color' => $this->faker->randomElement([
                '#1976D2', '#388E3C', '#D32F2F', '#F57C00',
                '#7B1FA2', '#C2185B', '#0097A7', '#616161',
            ]),
        ];
    }
}
