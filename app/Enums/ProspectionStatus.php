<?php

namespace App\Enums;

enum ProspectionStatus: string
{
    case Contacted = 'contacted';
    case OwnerAbsent = 'owner_absent';
    case HasCurrentStock = 'has_current_stock';
    case InterestedUndecided = 'interested_undecided';
    case HasSupplier = 'has_supplier';
    case NotInterested = 'not_interested';
    case SpecificProductRequest = 'specific_product_request';
    case Acquired = 'acquired';

    public function label(): string
    {
        return match ($this) {
            self::Contacted => 'Contacté',
            self::OwnerAbsent => 'Propriétaire absent',
            self::HasCurrentStock => 'Stock actuel suffisant',
            self::InterestedUndecided => 'Intéressé mais indécis',
            self::HasSupplier => 'A déjà un fournisseur',
            self::NotInterested => 'Pas intéressé',
            self::SpecificProductRequest => 'Demande produit spécifique',
            self::Acquired => 'Acquis (1ère commande)',
        };
    }

    /** Returns the Vuetify color name to use for badges and chips. */
    public function color(): string
    {
        return match ($this) {
            self::Contacted => 'blue',
            self::OwnerAbsent => 'orange',
            self::HasCurrentStock => 'teal',
            self::InterestedUndecided => 'purple',
            self::HasSupplier => 'indigo',
            self::NotInterested => 'error',
            self::SpecificProductRequest => 'cyan',
            self::Acquired => 'success',
        };
    }

    /** Returns true for statuses where no further prospection visits are expected. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::NotInterested, self::Acquired => true,
            default => false,
        };
    }
}
