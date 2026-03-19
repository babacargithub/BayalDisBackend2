<?php

namespace App\Enums;

enum CarLoadExpenseType: string
{
    case Fuel = 'FUEL';
    case Parking = 'PARKING';
    case Wash = 'WASH';
    case PoliceFine = 'POLICE_FINE';
    case Credit = 'CREDIT';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Carburant',
            self::Parking => 'Parking',
            self::Wash => 'Lavage',
            self::PoliceFine => 'Amende Police',
            self::Credit => 'CréditTh',
            self::Other => 'Autre',
        };
    }
}
