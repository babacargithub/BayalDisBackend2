<?php

namespace App\Enums;

enum CarLoadItemSource: string
{
    /**
     * Item was loaded directly from the warehouse into the car load.
     * Warehouse stock was decremented when this item was added.
     */
    case Warehouse = 'warehouse';

    /**
     * Item was created inside the car load by transforming a parent product into its variants.
     * No warehouse stock was decremented — the parent's car load stock was consumed instead.
     */
    case TransformedFromParent = 'transformed_from_parent';

    /**
     * Item was carried over from a previous car load's inventory (remaining unsold stock).
     * Warehouse stock was not decremented — it came from the previous car load.
     */
    case FromPreviousCarLoad = 'from_previous_car_load';

    public function isFromWarehouse(): bool
    {
        return $this === self::Warehouse;
    }

    public function isTransformed(): bool
    {
        return $this === self::TransformedFromParent;
    }

    public function isFromPreviousCarLoad(): bool
    {
        return $this === self::FromPreviousCarLoad;
    }

    /**
     * Returns true for item sources that represent stock transferred into this car load
     * without consuming fresh warehouse stock (i.e., came from within the car load ecosystem).
     */
    public function isInternalTransfer(): bool
    {
        return $this === self::TransformedFromParent || $this === self::FromPreviousCarLoad;
    }
}
