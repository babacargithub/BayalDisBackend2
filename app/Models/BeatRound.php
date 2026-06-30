<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class BeatRound extends Model
{
    protected $fillable = [
        'name',
        'planned_at',
        'week_day',
        'commercial_id',
        'beat_id',
        'vehicle_id',
        'odometer_start_km',
        'odometer_end_km',
        'strike_rate',
    ];

    protected function casts(): array
    {
        return [
            'planned_at' => 'date',
            'odometer_start_km' => 'integer',
            'odometer_end_km' => 'integer',
            'strike_rate' => 'float',
        ];
    }

    protected static function booted(): void
    {
        $invalidateOdometerCache = function (BeatRound $round): void {
            $date = $round->planned_at->toDateString();
            Cache::forget(self::odometerBeatIdIndexCacheKey($round->commercial_id, $date));
            Cache::forget(self::odometerRecordedCacheKey($round->beat_id, $round->commercial_id, $date));
        };

        static::created($invalidateOdometerCache);
        static::updated($invalidateOdometerCache);
        static::deleted($invalidateOdometerCache);
    }

    /**
     * Index key: commercial_id + date → beat_id.
     * Allows the controller to resolve the beat without a DB query on cache hits.
     */
    public static function odometerBeatIdIndexCacheKey(int $commercialId, string $date): string
    {
        return "beat_round_beat_id_index:{$commercialId}:{$date}";
    }

    /**
     * Main odometer flag key: beat_id + commercial_id + date → true.
     */
    public static function odometerRecordedCacheKey(int $beatId, int $commercialId, string $date): string
    {
        return "beat_round_odometer_recorded:{$beatId}:{$commercialId}:{$date}";
    }

    public function beat(): BelongsTo
    {
        return $this->belongsTo(Beat::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(BeatStop::class);
    }

    /**
     * Distance in km for this round trip.
     * Null when either odometer reading is missing.
     */
    protected function distanceKm(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->odometer_start_km !== null && $this->odometer_end_km !== null)
                ? ($this->odometer_end_km - $this->odometer_start_km)
                : null,
        );
    }
}
