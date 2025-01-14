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
} 