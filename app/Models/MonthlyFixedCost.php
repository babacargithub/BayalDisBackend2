<?php

namespace App\Models;

use App\Enums\MonthlyFixedCostPool;
use App\Enums\MonthlyFixedCostSubCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyFixedCost extends Model
{
    /** @use HasFactory<\Database\Factories\MonthlyFixedCostFactory> */
    use HasFactory;

    protected $fillable = [
        'cost_pool',
        'sub_category',
        'amount',
        'label',
        'period_year',
        'period_month',
        'per_vehicle_amount',
        'active_vehicle_count',
        'finalized_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'cost_pool' => MonthlyFixedCostPool::class,
            'sub_category' => MonthlyFixedCostSubCategory::class,
            'amount' => 'integer',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'per_vehicle_amount' => 'integer',
            'active_vehicle_count' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }
}
