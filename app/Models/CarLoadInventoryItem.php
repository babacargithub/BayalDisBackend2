<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLoadInventoryItem extends Model
{
    protected $fillable = [
        'car_load_inventory_id',
        'product_id',
        'total_loaded',
        'total_sold',
        'total_returned',
        'comment'
    ];

    protected $casts = [
//        'total_loaded' => 'integer',
//        'total_returned' => 'integer',
    ];

    public function carLoadInventory(): BelongsTo
    {
        return $this->belongsTo(CarLoadInventory::class, 'car_load_inventory_id');
    }

  /*  public function getTotalSoldAttribute()
    {
        // if product is parent, get all children and sum their sales
//        if ($this->product->is_base_product) {
//            // use joins to get all children sales
//                            $sqlQuery = '
//                            SELECT
//                    p.id AS parent_id,
//                    p.name AS parent_name,
//                    p.base_quantity AS parent_total_items,
//                    SUM(CASE
//                        WHEN v.product_id = p.id THEN v.quantity  -- Direct sales of parent product
//                        ELSE (child.base_quantity * v.quantity / p.base_quantity) -- Variant sales converted to parent equivalent
//                    END) AS total_parent_equivalent_sold,
//                    SUM(CASE
//                        WHEN v.product_id = p.id THEN v.quantity * p.base_quantity
//                        ELSE v.quantity * child.base_quantity
//                    END) AS total_items_sold,
//                    SUM(v.price * v.quantity) AS total_revenue,
//                    SUM(v.profit) AS total_profit
//                FROM
//                    products p
//                LEFT JOIN
//                    products child ON child.parent_id = p.id
//                LEFT JOIN
//                    ventes v ON v.product_id = p.id OR v.product_id = child.id
//                WHERE
//                    p.parent_id IS NULL
//                    AND p.id = ' . $this->product_id . '
//                    AND DATE(v.created_at) BETWEEN "' . $this->carLoadInventory->carLoad->load_date->toDateString() .
//                                '" AND "' .
//                                $this->carLoadInventory->carLoad->return_date->toDateString() . '"
//                    -- Only include parent products
//                GROUP BY
//                    p.id, p.name, p.base_quantity
//                ORDER BY
//                    total_revenue DESC;';
//            $result = \DB::select($sqlQuery);
//            if (count($result) > 0) {
//                return $result[0]->total_parent_equivalent_sold;
//            }
//        }
        return Vente::where("product_id", $this->product_id)
            ->whereBetween("created_at", [
                $this->carLoadInventory->carLoad->load_date,
                $this->carLoadInventory->carLoad->return_date
            ])
            // TODO filter by team
            ->sum("quantity");
    }*/

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
} 