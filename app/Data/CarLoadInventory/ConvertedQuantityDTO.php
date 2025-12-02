<?php

namespace App\Data\CarLoadInventory;

class ConvertedQuantityDTO
{
    public function __construct(

        public int $parentQuantity = 0,
        public int $childQuantity = 0,
        public string $childName = ''
    )
    {


    }

    /**
     * Determines if the instance has child quantity based on the child quantity, meaning it is composed.
     * @return bool
     */
    public function isMixed(): bool
    {
        return $this->childQuantity > 0;

    }


}