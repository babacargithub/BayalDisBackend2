<?php

namespace App\Enums;

enum BeatStopStatus: string
{
    case Planned = 'planned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case StockRestant = 'stock_restant';
    case RestaurantFerme = 'restaurant_ferme';
    case ProduitsNonDisponibles = 'produits_non_disponibles';
    case DetteNonAcceptee = 'dette_non_acceptee';
    case Reprogramme = 'reprogramme';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Prévu',
            self::Completed => 'Visite effectuée',
            self::Cancelled => 'Visite annulée',
            self::StockRestant => 'Le client a un stock restant',
            self::RestaurantFerme => 'Restaurant fermé lors du passage',
            self::ProduitsNonDisponibles => 'Produits demandés non disponibles',
            self::DetteNonAcceptee => 'Cumul de dette non accepté',
            self::Reprogramme => 'Reprogrammé',
        };
    }

    /** Returns true for statuses representing a visit attempt that produced no sale. */
    public function isNoSale(): bool
    {
        return in_array($this, [
            self::StockRestant,
            self::RestaurantFerme,
            self::ProduitsNonDisponibles,
            self::DetteNonAcceptee,
        ], strict: true);
    }

    /** @return list<string> The string values of all no-sale statuses. */
    public static function noSaleValues(): array
    {
        return [
            self::StockRestant->value,
            self::RestaurantFerme->value,
            self::ProduitsNonDisponibles->value,
            self::DetteNonAcceptee->value,
            self::Reprogramme->value,
        ];
    }
}
