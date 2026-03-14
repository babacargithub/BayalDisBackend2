<?php

namespace App\Models;

use App\Enums\CarLoadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class CarLoad extends Model
{
    protected $fillable = [
        'name',
        'load_date',
        'return_date',
        'team_id',
        'status',
        'comment',
        'returned',
        'previous_car_load_id',
    ];

    protected $casts = [
        'load_date' => 'datetime',
        'return_date' => 'datetime',
        'returned' => 'boolean',
        'status' => CarLoadStatus::class,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CarLoadItem::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(CarLoadInventory::class);
    }

    public function getStockValueAttribute(): int
    {
        // Terminated car loads have transferred all remaining stock to the next car load.
        if ($this->status === CarLoadStatus::TerminatedAndTransferred) {
            return 0;
        }

        return (int) DB::table('car_load_items')
            ->join('products', 'products.id', '=', 'car_load_items.product_id')
            ->where('car_load_items.car_load_id', $this->id)
            ->where('car_load_items.quantity_left', '>', 0)
            ->sum(DB::raw('car_load_items.quantity_left * products.cost_price'));
    }
}
