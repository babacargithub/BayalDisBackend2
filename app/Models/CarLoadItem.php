<?php

namespace App\Models;

use App\Enums\CarLoadItemSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $quantity_left
 * @property int|null $cost_price_per_unit
 * @property CarLoadItemSource $source
 * @property int|null $from_previous_car_load_id
 * @property-read bool $from_previous_car_load
 */
class CarLoadItem extends Model
{
    protected $fillable = [
        'car_load_id',
        'product_id',
        'quantity_loaded',
        'quantity_left',
        'cost_price_per_unit',
        'comment',
        'loaded_at',
        'source',
        'from_previous_car_load_id',
    ];

    protected $casts = [
        'quantity_loaded' => 'integer',
        'quantity_left' => 'integer',
        'cost_price_per_unit' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'loaded_at' => 'datetime',
        'source' => CarLoadItemSource::class,
    ];

    /**
     * Backward-compatible accessor: true when this item rolled over from a previous
     * car load. Driven by the FK so the boolean DB column is no longer the source
     * of truth — old code that reads $item->from_previous_car_load still works.
     */
    public function getFromPreviousCarLoadAttribute(): bool
    {
        return $this->from_previous_car_load_id !== null;
    }

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The car load this item physically rolled over from (null for warehouse-loaded
     * and transformed items).
     */
    public function originCarLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class, 'from_previous_car_load_id');
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
