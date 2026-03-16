<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = [
        'commercial_work_period_id',
        'base_commission',
        'basket_bonus',
        'objective_bonus',
        'total_penalties',
        'net_commission',
        'basket_achieved',
        'basket_multiplier_applied',
        'achieved_tier_level',
        'is_finalized',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'base_commission' => 'integer',
            'basket_bonus' => 'integer',
            'objective_bonus' => 'integer',
            'total_penalties' => 'integer',
            'net_commission' => 'integer',
            'basket_achieved' => 'boolean',
            'basket_multiplier_applied' => 'decimal:2',
            'achieved_tier_level' => 'integer',
            'is_finalized' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }

    public function workPeriod(): BelongsTo
    {
        return $this->belongsTo(CommercialWorkPeriod::class, 'commercial_work_period_id');
    }

    public function paymentLines(): HasMany
    {
        return $this->hasMany(CommissionPaymentLine::class);
    }

    /**
     * Convenience accessor — delegates to the work period.
     * Eager-load workPeriod to avoid N+1 when accessing this on a collection.
     */
    protected function periodStartDate(): Attribute
    {
        return Attribute::get(fn () => $this->workPeriod?->period_start_date);
    }

    /**
     * Convenience accessor — delegates to the work period.
     * Eager-load workPeriod to avoid N+1 when accessing this on a collection.
     */
    protected function periodEndDate(): Attribute
    {
        return Attribute::get(fn () => $this->workPeriod?->period_end_date);
    }
}
