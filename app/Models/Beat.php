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

    public function rounds(): HasMany
    {
        return $this->hasMany(BeatRound::class);
    }

    /**
     * Template stops define the recurring customer list (beat_round_id IS NULL).
     * These are the "blueprint" — they never get a status or visited_at.
     */
    public function templateStops(): HasMany
    {
        return $this->hasMany(BeatStop::class)->whereNull('beat_round_id');
    }

    /**
     * Look up the BeatRound for this beat on the given date. Returns null if
     * no round has been explicitly created for that date yet.
     */
    public function findRoundForDate(Carbon $date): ?BeatRound
    {
        return BeatRound::where('beat_id', $this->id)
            ->whereDate('planned_at', $date->toDateString())
            ->first();
    }

    /**
     * Return stops for an existing round, generating them from template stops
     * if the round has no stops yet. The round must already exist.
     */
    public function getOrGenerateStopsForRound(BeatRound $round): Collection
    {
        $existingStops = $this->stops()
            ->where('beat_round_id', $round->id)
            ->with('customer')
            ->get();

        if ($existingStops->isNotEmpty()) {
            return $existingStops;
        }

        $templateStops = $this->templateStops()->get(['customer_id', 'display_position']);

        if ($templateStops->isEmpty()) {
            return new Collection;
        }

        foreach ($templateStops as $template) {
            $this->stops()->create([
                'customer_id' => $template->customer_id,
                'beat_round_id' => $round->id,
                'status' => BeatStop::STATUS_PLANNED,
                'display_position' => $template->display_position,
            ]);
        }

        return $this->stops()
            ->where('beat_round_id', $round->id)
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
