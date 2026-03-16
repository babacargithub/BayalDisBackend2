<?php

namespace App\Enums;

enum MonthlyFixedCostSubCategory: string
{
    // Storage pool
    case WarehouseRent = 'warehouse_rent';
    case Electricity = 'electricity';
    case Wifi = 'wifi';
    case Security = 'security';

    // Overhead pool
    case ManagerSalary = 'manager_salary';
    case BankInterest = 'bank_interest';
    case LossesBreakage = 'losses_breakage';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WarehouseRent => 'Loyer dépôt',
            self::Electricity => 'Électricité',
            self::Wifi => 'Wifi',
            self::Security => 'Gardiennage',
            self::ManagerSalary => 'Salaire manager',
            self::BankInterest => 'Intérêts bancaires',
            self::LossesBreakage => 'Pertes / casse',
            self::Other => 'Autre',
        };
    }

    public function pool(): MonthlyFixedCostPool
    {
        return match ($this) {
            self::WarehouseRent,
            self::Electricity,
            self::Wifi,
            self::Security => MonthlyFixedCostPool::Storage,
            self::ManagerSalary,
            self::BankInterest,
            self::LossesBreakage,
            self::Other => MonthlyFixedCostPool::Overhead,
        };
    }
}
