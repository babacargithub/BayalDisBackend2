<?php

namespace App\Enums;

enum AccountDebtStatus: string
{
    /** Debt has been created and no repayment has been made yet. */
    case Pending = 'PENDING';

    /** At least one partial repayment has been made but the debt is not fully settled. */
    case PartiallyRepaid = 'PARTIALLY_REPAID';

    /** The full borrowed amount has been restored to the creditor account. */
    case FullyRepaid = 'FULLY_REPAID';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::PartiallyRepaid => 'Partiellement remboursé',
            self::FullyRepaid => 'Remboursé',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::FullyRepaid;
    }
}
