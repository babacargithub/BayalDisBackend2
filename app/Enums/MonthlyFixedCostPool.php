<?php

namespace App\Enums;

enum MonthlyFixedCostPool: string
{
    /**
     * Costs directly related to warehouse occupancy:
     * rent, electricity, wifi, security.
     * Distributed equally per active vehicle for the month.
     */
    case Storage = 'storage';

    /**
     * Company-wide overhead costs:
     * manager salary, bank interest, losses/breakage.
     * Distributed equally per active vehicle for the month.
     */
    case Overhead = 'overhead';

    public function label(): string
    {
        return match ($this) {
            self::Storage => 'Stockage',
            self::Overhead => 'Frais généraux',
        };
    }
}
