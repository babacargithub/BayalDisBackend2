<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitBatch extends Model
{
    protected $fillable = [
        'name',
        'visit_date',
        'commercial_id',
    ];

    protected $casts = [
        'visit_date' => 'date',
    ];

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CustomerVisit::class);
    }
} 