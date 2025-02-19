<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CarLoad extends Model
{
    protected $fillable = [
        'name',
        'load_date',
        'return_date',
        'commercial_id',
        'status', // LOADING, ACTIVE, UNLOADED
        'comment',
        "returned",
        'previous_car_load_id'
    ];

    protected $casts = [
        'load_date' => 'datetime',
        'return_date' => 'datetime',
        'returned' => 'boolean'
    ];

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CarLoadItem::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(CarLoadInventory::class);
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