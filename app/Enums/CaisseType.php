<?php

namespace App\Enums;

enum CaisseType: string
{
    /**
     * Back-office cash register. Receives versements from commercials
     * and is the source for expense payments and commission payouts.
     */
    case Main = 'MAIN';

    /**
     * Personal cash register assigned to a commercial.
     * Receives customer payments and is swept to a main caisse at versement time.
     */
    case Commercial = 'COMMERCIAL';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Caisse principale',
            self::Commercial => 'Caisse commercial',
        };
    }
}
