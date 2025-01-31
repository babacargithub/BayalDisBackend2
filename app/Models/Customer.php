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
        'ligne_id',
        'description',
        'is_prospect',
        'customer_category_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_prospect' => 'boolean',
    ];

    protected $appends = ['last_visit'];

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
    public function ligne(): BelongsTo
    {
        return $this->belongsTo(Ligne::class);
    }

    /**
     * Scope a query to only include prospects
     */
    public function scopeProspects($query)
    {
        return $query->where('is_prospect', true);
    }

    /**
     * Scope a query to only include non-prospects (customers with sales)
     */
    public function scopeNonProspects($query)
    {
        return $query->where('is_prospect', false);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getLastVisitAttribute()
    {
        // Check for last completed or cancelled visit
        $lastVisit = $this->visits()
            ->whereIn('status', ['completed', 'cancelled'])
            ->latest('visited_at')
            ->first();

        if ($lastVisit && $lastVisit->visited_at) {
            return $lastVisit->visited_at;
        }

        // If no visit, check for last sale
        $lastSale = $this->ventes()
            ->latest('created_at')
            ->first();

        if ($lastSale) {
            return $lastSale->created_at;
        }

        // If no visit and no sale, return customer creation date
        return $this->created_at;
    }

    public function visits()
    {
        return $this->hasMany(CustomerVisit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'customer_category_id');
    }
} 