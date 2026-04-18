<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Beat extends Model
{
    protected $fillable = [
        'name',
        'day_of_week',
        'commercial_id',
        'sector_id',
    ];

    protected $casts = [
        'day_of_week' => DayOfWeek::class,
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(BeatStop::class);
    }

    /**
     * Template stops define the recurring customer list (visit_date IS NULL).
     * These are the "blueprint" — they never get a status or visited_at.
     */
    public function templateStops(): HasMany
    {
        return $this->hasMany(BeatStop::class)->whereNull('visit_date');
    }

    /**
     * Occurrence stops for a specific date. If none exist yet, generates them
     * from the template stops so the commercial can start completing visits.
     */
    public function getOrGenerateStopsForDate(Carbon $date): Collection
    {
        $dateString = $date->toDateString();

        $existingOccurrenceStops = $this->stops()
            ->whereDate('visit_date', $dateString)
            ->with('customer')
            ->get();

        if ($existingOccurrenceStops->isNotEmpty()) {
            return $existingOccurrenceStops;
        }

        $templateCustomerIds = $this->templateStops()->pluck('customer_id');

        if ($templateCustomerIds->isEmpty()) {
            return new Collection();
        }

        $generatedStops = collect();
        foreach ($templateCustomerIds as $customerId) {
            $stop = $this->stops()->create([
                'customer_id' => $customerId,
                'visit_date' => $dateString,
                'status' => BeatStop::STATUS_PLANNED,
            ]);
            $generatedStops->push($stop);
        }

        return $this->stops()
            ->whereDate('visit_date', $dateString)
            ->with('customer')
            ->get();
    }

    /**
     * Scope to beats scheduled for a given day of week.
     */
    public function scopeForDayOfWeek($query, DayOfWeek $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek->value);
    }
}
