<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected function casts(): array
    {
        return [
            'planned_at' => 'date',
            'odometer_start_km' => 'integer',
            'odometer_end_km' => 'integer',
        ];
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
