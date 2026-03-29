<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /* @var $this Product */

        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'credit_price' => $this->credit_price,
        ];
    }
}
