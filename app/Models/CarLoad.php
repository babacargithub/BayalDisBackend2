<?php

namespace App\Models;

use App\Enums\CarLoadStatus;
use App\Services\Abc\AbcVehicleCostService;
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
        'vehicle_id',
        'fixed_daily_cost',
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
        'fixed_daily_cost' => 'integer',
    ];

    protected static function booted(): void
    {
        // Snapshot the vehicle's daily fixed cost rate whenever vehicle_id changes.
        // This freezes the rate at assignment time so historical trip costs stay accurate
        // even if the vehicle's monthly cost structure is updated later.
        static::saving(function (CarLoad $carLoad): void {
            if ($carLoad->isDirty('vehicle_id')) {
                if ($carLoad->vehicle_id === null) {
                    $carLoad->fixed_daily_cost = null;
                } else {
                    $vehicle = Vehicle::find($carLoad->vehicle_id);
                    if ($vehicle !== null) {
                        $carLoad->fixed_daily_cost = app(AbcVehicleCostService::class)
                            ->computeDailyFixedCost($vehicle);
                    }
                }
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function fuelEntries(): HasMany
    {
        return $this->hasMany(CarLoadFuelEntry::class);
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
