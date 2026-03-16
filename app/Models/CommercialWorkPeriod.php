<?php

namespace App\Models;

use App\Data\Commission\CommissionPeriodData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Represents a defined time window (week, bi-weekly, or month) during which a
 * commercial's sales activity is tracked and evaluated for commission purposes.
 *
 * All commission-related records — Commission, CommercialObjectiveTier, CommercialPenalty —
 * belong to a CommercialWorkPeriod rather than repeating the (commercial_id, period_start_date,
 * period_end_date) triplet directly on each table.
 */
class CommercialWorkPeriod extends Model
{
    protected $fillable = [
        'commercial_id',
        'period_start_date',
        'period_end_date',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
        ];
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    public function objectiveTiers(): HasMany
    {
        return $this->hasMany(CommercialObjectiveTier::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(CommercialPenalty::class);
    }

    /**
     * Returns true if the given commercial already has a work period whose date range
     * overlaps with $newPeriod (but is not the same record as $excludeId).
     *
     * Two periods overlap iff: existing_start <= new_end AND existing_end >= new_start.
     */
    public static function hasOverlappingPeriodForCommercial(
        int $commercialId,
        CommissionPeriodData $newPeriod,
        ?int $excludeId = null,
    ): bool {
        return self::query()
            ->where('commercial_id', $commercialId)
            ->where('period_start_date', '<=', $newPeriod->endDate->startOfDay())
            ->where('period_end_date', '>=', $newPeriod->startDate->startOfDay())
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();
    }
}
