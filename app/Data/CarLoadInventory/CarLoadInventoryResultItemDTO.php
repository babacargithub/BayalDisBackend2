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

}