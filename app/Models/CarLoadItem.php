<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $quantity_left
 */
class CarLoadItem extends Model
{
    protected $fillable = [
        'car_load_id',
        'product_id',
        'quantity_loaded',
        "quantity_left",
        'comment',
        "loaded_at",
        'from_previous_car_load'
    ];

    protected $casts = [
//        'quantity_loaded' => 'integer',
        "created_at" => "datetime",
        "updated_at" => "datetime",
        "loaded_at" => "datetime"
    ];
    protected $guarded = ['id',"quantity_left"];

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function increaseQuantityLeft(int $quantity): void
    {
        $this->quantity_left += $quantity;
        $this->save();
    }
    public function decreaseQuantityLeft(int $quantity): void
    {
        $this->quantity_left -= $quantity;
        $this->save();

    }


}
