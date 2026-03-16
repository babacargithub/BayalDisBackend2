<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialObjectiveTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'commercial_work_period_id',
        'tier_level',
        'ca_threshold',
        'bonus_amount',
    ];

    protected function casts(): array
    {
        return [
            'tier_level' => 'integer',
            'ca_threshold' => 'integer',
            'bonus_amount' => 'integer',
        ];
    }

    public function workPeriod(): BelongsTo
    {
        return $this->belongsTo(CommercialWorkPeriod::class, 'commercial_work_period_id');
    }
}
