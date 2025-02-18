<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarLoadInventory extends Model
{
    protected $fillable = [
        'name',
        'car_load_id',
        'user_id',
        'closed',
        'comment'
    ];

    protected $casts = [
        'closed' => 'boolean'
    ];

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CarLoadInventoryItem::class);
    }
} 