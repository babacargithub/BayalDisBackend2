<?php

namespace App\Models;

use App\Data\Commission\CommissionPeriodData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommissionPeriodSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_start_date',
        'period_end_date',
        'basket_multiplier',
        'required_category_ids',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'basket_multiplier' => 'decimal:2',
            'required_category_ids' => 'array',
        ];
    }

    /**
     * Find the settings for a given period, or return null if not configured.
     */
    public static function forPeriod(CommissionPeriodData $period): ?self
    {
        return self::where('period_start_date', $period->startDate->startOfDay())
            ->where('period_end_date', $period->endDate->startOfDay())
            ->first();
    }

    /**
     * Returns true if a period setting already exists whose date range overlaps with the given period.
     * Pass $excludeId when updating an existing record so it is not compared against itself.
     */
    public static function hasOverlappingPeriod(CommissionPeriodData $newPeriod, ?int $excludeId = null): bool
    {
        return self::query()
            ->where('period_start_date', '<=', $newPeriod->endDate->startOfDay())
            ->where('period_end_date', '>=', $newPeriod->startDate->startOfDay())
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();
    }
}
