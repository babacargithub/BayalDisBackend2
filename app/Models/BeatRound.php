<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'planned_at' => 'date',
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

    public function stops(): HasMany
    {
        return $this->hasMany(BeatStop::class);
    }
}
