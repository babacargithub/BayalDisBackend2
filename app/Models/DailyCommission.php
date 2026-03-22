<?php

/** @noinspection ALL */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stores the computed commission for a single work day within a CommercialWorkPeriod.
 *
 * One DailyCommission record is created (or updated) automatically after each Payment
 * via RecalculateDailyCommissionJob. The combination (commercial_work_period_id, work_day)
 * is unique.
 *
 * Per-day fields:
 *  - base_commission      : SUM of all commission_payment_lines.commission_amount for that day
 *  - basket_bonus         : bonus applied if all required categories were sold that day
 *  - objective_bonus      : bonus for hitting the highest objective tier that day
 *  - total_penalties      : SUM of commercial_penalties.amount where work_day = this day
 *  - net_commission       : max(0, base + basket + objective − penalties)
 *  - basket_achieved      : whether the basket condition was met that day
 *  - basket_multiplier_applied : the multiplier that was used (e.g. 1.30)
 *  - achieved_tier_level  : tier level of the highest achieved objective tier that day
 *
 * @method static create(array $array)
 */
class DailyCommission extends Model
{
    protected $table = 'daily_commissions';

    protected $fillable = [
        'commercial_work_period_id',
        'work_day',
        'base_commission',
        'basket_bonus',
        'objective_bonus',
        'total_penalties',
        'new_confirmed_customers_bonus',
        'new_prospect_customers_bonus',
        'mandatory_daily_threshold',
        'mandatory_threshold_reached',
        'cached_average_margin_rate',
        'net_commission',
        'basket_achieved',
        'basket_multiplier_applied',
        'achieved_tier_level',
    ];

    protected function casts(): array
    {
        return [
            'work_day' => 'date',
            'base_commission' => 'integer',
            'basket_bonus' => 'integer',
            'objective_bonus' => 'integer',
            'total_penalties' => 'integer',
            'new_confirmed_customers_bonus' => 'integer',
            'new_prospect_customers_bonus' => 'integer',
            'mandatory_daily_threshold' => 'integer',
            'mandatory_threshold_reached' => 'boolean',
            'cached_average_margin_rate' => 'decimal:4',
            'net_commission' => 'integer',
            'basket_achieved' => 'boolean',
            'basket_multiplier_applied' => 'decimal:2',
            'achieved_tier_level' => 'integer',
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
}
