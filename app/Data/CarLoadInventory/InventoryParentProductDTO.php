<?php

namespace App\Data\CarLoadInventory;

use App\Models\Product;

class InventoryParentProductDTO
{


    public function __construct(
        public string $name,
    )
    {
    }

    public static function fromProduct(Product $product): self
    {
        return new self(name: $product->name);

    }
}