<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    /** @use HasFactory<\Database\Factories\VehicleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'plate_number',
        'insurance_monthly',
        'maintenance_monthly',
        'repair_reserve_monthly',
        'depreciation_monthly',
        'driver_salary_monthly',
        'working_days_per_month',
        'estimated_daily_fuel_consumption',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'insurance_monthly' => 'integer',
            'maintenance_monthly' => 'integer',
            'repair_reserve_monthly' => 'integer',
            'depreciation_monthly' => 'integer',
            'driver_salary_monthly' => 'integer',
            'working_days_per_month' => 'integer',
            'estimated_daily_fuel_consumption' => 'integer',
        ];
    }

    public function carLoads(): HasMany
    {
        return $this->hasMany(CarLoad::class);
    }

    public function fuelEntries(): HasMany
    {
        return $this->hasMany(CarLoadFuelEntry::class, 'car_load_id')
            ->whereHas('carLoad', fn ($query) => $query->where('vehicle_id', $this->id));
    }

    /**
     * Total monthly fixed cost for this vehicle.
     * Fuel cost is prorated: estimated_daily_fuel_consumption × working_days_per_month.
     * Actual fuel receipts per trip are tracked separately via CarLoadFuelEntry.
     */
    public function getTotalMonthlyFixedCostAttribute(): int
    {
        $estimatedMonthlyFuelCost = $this->estimated_daily_fuel_consumption * $this->working_days_per_month;

        return $this->insurance_monthly
            + $this->maintenance_monthly
            + $this->repair_reserve_monthly
            + $this->depreciation_monthly
            + $this->driver_salary_monthly
            + $estimatedMonthlyFuelCost;
    }

    /**
     * Daily fixed cost based on working days per month.
     * Includes estimated daily fuel cost in XOF.
     * Actual fuel receipts per trip are tracked separately via CarLoadFuelEntry.
     */
    public function getDailyFixedCostAttribute(): int
    {
        if ($this->working_days_per_month === 0) {
            return 0;
        }

        $nonFuelDailyCost = (int) round(
            ($this->insurance_monthly
                + $this->maintenance_monthly
                + $this->repair_reserve_monthly
                + $this->depreciation_monthly
                + $this->driver_salary_monthly
            ) / $this->working_days_per_month
        );

        return $nonFuelDailyCost + $this->estimated_daily_fuel_consumption;
    }
}
