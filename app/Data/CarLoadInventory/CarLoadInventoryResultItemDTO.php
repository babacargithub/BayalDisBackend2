<?php

namespace App\Data\CarLoadInventory;

use Illuminate\Support\Collection;

class CarLoadInventoryResultItemDTO
{
    public function __construct(
        public InventoryParentProductDTO $parent,
        public float $totalLoaded = 0,
        public float $totalReturned = 0,
        public float $totalSold = 0,
        public Collection                $children,
        public ConvertedQuantityDTO      $totalLoadedConverted,
        public ConvertedQuantityDTO      $totalSoldConverted,
        public ConvertedQuantityDTO      $totalReturnedConverted,
        public ConvertedQuantityDTO $resultConverted,
        public float $resultOfComputation = 0,
        public int $priceOfResultComputation = 0
    )
    {


    }

    /**
     * Tells if the inventory counting of items is OK ! This means the items counted physically matches the expected
     * @return bool
     */
    public function isCountingOK(): bool
    {
        return $this->resultConverted->parentQuantity == 0 && $this->resultConverted->childQuantity == 0;

    }
    /**
     * Tells if the inventory counting of items is higher than expected ! This means the items counted physically
     * are higher than normal
     * expected
     * @return bool
     */
    public function isSurplus(): bool
    {
        return $this->resultOfComputation > 0;

    }
    public function  isDeficit(): bool
    {
        return $this->resultOfComputation < 0;

    }

}