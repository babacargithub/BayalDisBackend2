<?php

namespace App\Enums;

enum CarLoadStatus: string
{
    /**
     * Car load is being prepared in the warehouse — items are being loaded onto the vehicle.
     * Articles can be added or edited. The car load has not yet left for the field.
     */
    case Loading = 'LOADING';

    /**
     * Vehicle is active in the field and salespersons are recording sales.
     * No more items can be added to the load.
     */
    case Selling = 'SELLING';

    /**
     * A physical inventory count has been initiated but not yet closed.
     * The car load is at the end of its cycle; totals are being reconciled.
     */
    case OngoingInventory = 'ONGOING_INVENTORY';

    /**
     * Inventory count is complete and has been closed.
     * The car load is fully settled. A new car load can be created from it.
     */
    case FullInventory = 'FULL_INVENTORY';

    /**
     * A new car load has been created from this one (stock transferred to successor).
     * This car load is archived and no further action can be taken on it.
     */
    case TerminatedAndTransferred = 'TERMINATED_AND_TRANSFERRED';

    /** Returns true for any status that prevents further item or inventory modifications. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::FullInventory, self::TerminatedAndTransferred => true,
            default => false,
        };
    }
}
