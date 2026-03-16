<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialPenalty extends Model
{
    use HasFactory;

    protected $fillable = [
        'commercial_work_period_id',
        'amount',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function workPeriod(): BelongsTo
    {
        return $this->belongsTo(CommercialWorkPeriod::class, 'commercial_work_period_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
