<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLoadInventoryItem extends Model
{
    protected $fillable = [
        'car_load_id',
        'product_id',
        'total_loaded',
        'total_sold',
        'total_returned',
        'comment'
    ];

    protected $casts = [    
        'total_loaded' => 'integer',
        'total_sold' => 'integer',
        'total_returned' => 'integer',
    ];

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
} 