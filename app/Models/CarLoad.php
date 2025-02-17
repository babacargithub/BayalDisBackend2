<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarLoad extends Model
{
    protected $fillable = [
        'name',
        'load_date',
        'return_date',
        'commercial_id',
        'status', // LOADING, ACTIVE, UNLOADED
        'comment',
        'previous_car_load_id'
    ];

    protected $casts = [
        'load_date' => 'datetime',
        'return_date' => 'datetime',
    ];

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CarLoadInventoryItem::class);
    }

    public function previousCarLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class, 'previous_car_load_id');
    }

    public function nextCarLoad(): HasMany
    {
        return $this->hasMany(CarLoad::class, 'previous_car_load_id');
    }
} 