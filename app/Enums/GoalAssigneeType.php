<?php

namespace App\Enums;

enum GoalAssigneeType: string
{
    case Commercial = 'commercial';
    case Team = 'team';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Commercial',
            self::Team => 'Équipe',
            self::Company => 'Entreprise',
        };
    }
}
