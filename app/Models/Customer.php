<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['name', 'phone_number', 'owner_number', 'gps_coordinates', 'commercial_id'];

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }
} 