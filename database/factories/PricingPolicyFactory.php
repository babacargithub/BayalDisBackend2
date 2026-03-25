<?php

namespace Database\Factories;

use App\Models\PricingPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

class PricingPolicyFactory extends Factory
{
    protected $model = PricingPolicy::class;

    public function definition(): array
    {
        return [
            'name' => 'Default Policy',
            'active' => true,
            'surcharge_percent' => 10,
            'grace_days' => 0,
            'apply_to_deferred_only' => true,
            'apply_credit_price' => false,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['active' => false]);
    }
}
