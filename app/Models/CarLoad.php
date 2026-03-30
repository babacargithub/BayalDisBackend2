<?php

namespace App\Models;

use App\Enums\CarLoadStatus;
use App\Jobs\RecalculateInvoicesDeliveryCostJob;
use App\Services\Abc\AbcVehicleCostService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
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
        // Always refresh the vehicle's daily fixed cost rate on every save so that
        // updates to the car load (e.g. changing dates or any other field) always
        // reflect the vehicle's current monthly cost structure.
        static::saving(function (CarLoad $carLoad): void {
            if ($carLoad->vehicle_id === null) {
                $carLoad->fixed_daily_cost = null;
            } else {
                $vehicle = Vehicle::find($carLoad->vehicle_id);
                if ($vehicle !== null) {
                    $carLoad->fixed_daily_cost = app(AbcVehicleCostService::class)
                        ->computeDailyRunningCostForVehicle($vehicle);
                }
            }
        });

        // After the fixed_daily_cost is persisted, redistribute the daily delivery cost
        // across all today's invoices linked to this car load so financial totals stay current.
        static::saved(function (CarLoad $carLoad): void {
            RecalculateInvoicesDeliveryCostJob::dispatch(
                carLoadId: $carLoad->id,
                workDay: today()->toDateString(),
            );
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

    public function expenses(): HasMany
    {
        return $this->hasMany(CarLoadExpense::class);
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

    public function commercial(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            Team::class,
            'id',       // FK on Team matched against CarLoad's local key (team_id)
            'id',       // PK on User
            'team_id',  // local key on CarLoad
            'user_id'   // FK on Team pointing to the manager User
        );
    }
}
