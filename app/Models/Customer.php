<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_number',
        'owner_number',
        'address',
        'commercial_id',
        'gps_coordinates',
        'ligne_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['is_prospect'];

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    /**
     * Get the ligne that owns the restaurant.
     */
    public function ligne()
    {
        return $this->belongsTo(Ligne::class);
    }

    /**
     * Determine if the customer is a prospect (no sales yet)
     */
    public function getIsProspectAttribute(): bool
    {
        return !$this->ventes()->exists();
    }

    /**
     * Scope a query to only include prospects
     */
    public function scopeProspects($query)
    {
        return $query->whereDoesntHave('ventes');
    }

    /**
     * Scope a query to only include non-prospects (customers with sales)
     */
    public function scopeNonProspects($query)
    {
        return $query->whereHas('ventes');
    }
} 