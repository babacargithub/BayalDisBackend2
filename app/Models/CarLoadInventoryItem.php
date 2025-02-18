<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLoadInventoryItem extends Model
{
    protected $fillable = [
        'car_load_inventory_id',
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

    public function carLoadInventory(): BelongsTo
    {
        return $this->belongsTo(CarLoadInventory::class, 'car_load_inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
} 