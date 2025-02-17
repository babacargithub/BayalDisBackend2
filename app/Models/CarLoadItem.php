<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLoadItem extends Model
{
    protected $fillable = [
        'car_load_id',
        'product_id',
        'quantity_loaded',
        'comment'
    ];

    protected $casts = [
        'quantity_loaded' => 'integer',
        "created_at" => "datetime",
        "updated_at" => "datetime",
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
