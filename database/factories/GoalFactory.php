<?php

namespace Database\Factories;

use App\Enums\GoalAssigneeType;
use App\Enums\GoalMetric;
use App\Models\Commercial;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Goal>
 */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        $periodStart = $this->faker->dateTimeBetween('-6 months', 'now');
        $periodEnd = $this->faker->dateTimeBetween($periodStart, '+1 month');

        return [
            'assignee_type' => GoalAssigneeType::Commercial,
            'assignee_id' => Commercial::factory(),
            'metric' => $this->faker->randomElement(GoalMetric::cases()),
            'target_value' => $this->faker->randomFloat(2, 50, 100),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'created_by' => User::factory(),
        ];
    }

    public function forCompany(): static
    {
        return $this->state([
            'assignee_type' => GoalAssigneeType::Company,
            'assignee_id' => null,
        ]);
    }

    public function forCommercial(int $commercialId): static
    {
        return $this->state([
            'assignee_type' => GoalAssigneeType::Commercial,
            'assignee_id' => $commercialId,
        ]);
    }

    public function forTeam(int $teamId): static
    {
        return $this->state([
            'assignee_type' => GoalAssigneeType::Team,
            'assignee_id' => $teamId,
        ]);
    }

    public function withMetric(GoalMetric $metric, float $targetValue): static
    {
        return $this->state([
            'metric' => $metric,
            'target_value' => $targetValue,
        ]);
    }
}
