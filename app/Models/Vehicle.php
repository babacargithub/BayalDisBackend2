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
     * Total monthly fixed cost for this vehicle, excluding fuel
     * (fuel is entered per trip as actual receipts).
     */
    public function getTotalMonthlyFixedCostAttribute(): int
    {
        return $this->insurance_monthly
            + $this->maintenance_monthly
            + $this->repair_reserve_monthly
            + $this->depreciation_monthly
            + $this->driver_salary_monthly;
    }

    /**
     * Daily fixed cost based on working days per month.
     * Fuel is excluded — it is tracked per CarLoad via CarLoadFuelEntry.
     */
    public function getDailyFixedCostAttribute(): int
    {
        if ($this->working_days_per_month === 0) {
            return 0;
        }

        return (int) round($this->total_monthly_fixed_cost / $this->working_days_per_month);
    }
}
