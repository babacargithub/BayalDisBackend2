<?php

namespace Database\Factories;

use App\Enums\ProspectionStatus;
use App\Models\Commercial;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerProspectionEvent>
 */
class CustomerProspectionEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => fn () => Customer::create([
                'name' => fake()->name(),
                'phone_number' => fake()->numerify('7########'),
                'owner_number' => fake()->numerify('7########'),
                'gps_coordinates' => '14.6928,17.4467',
            ])->id,
            'commercial_id' => Commercial::factory(),
            'status' => ProspectionStatus::Contacted,
            'notes' => null,
            'scheduled_revisit_date' => null,
        ];
    }

    public function ownerAbsent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProspectionStatus::OwnerAbsent,
            'scheduled_revisit_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    public function interestedUndecided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProspectionStatus::InterestedUndecided,
            'notes' => fake()->sentence(),
        ]);
    }

    public function notInterested(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProspectionStatus::NotInterested,
        ]);
    }

    public function acquired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProspectionStatus::Acquired,
        ]);
    }
}
