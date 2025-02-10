<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sector extends Model
{
    protected $fillable = [
        'name',
        'boundaries',
        'ligne_id',
        'description'
    ];

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(Ligne::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_sectors')
            ->withTimestamps();
    }

    public function visitBatches(): HasMany
    {
        return $this->hasMany(VisitBatch::class);
    }
} 