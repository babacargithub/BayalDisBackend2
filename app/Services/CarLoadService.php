<?php

namespace App\Services;

use App\Models\CarLoad;
use App\Models\CarLoadInventoryItem;
use App\Models\CarLoadItem;
use App\Models\CarLoadInventory;
use App\Models\Product;
use App\Models\Team;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use JetBrains\PhpStorm\ArrayShape;

class CarLoadService
{
    public function createCarLoad(array $data): CarLoad
    {
        $carLoad = CarLoad::create([
            'name' => $data['name'],
            'team_id' => $data['team_id'],
            'comment' => $data['comment'] ?? null,
            'return_date' => $data['return_date'],
            'status' => 'LOADING',
            'load_date' => now(),
        ]);

        if (isset($data['items'])) {
            $carLoad->items()->createMany($data['items']);
        }

        return $carLoad;
    }

    public function updateCarLoad(CarLoad $carLoad, array $data): CarLoad
    {
        $carLoad->update([
            'name' => $data['name'],
            'team_id' => $data['team_id'],
            'comment' => $data['comment'] ?? null,
            'return_date' => $data['return_date'],
        ]);

        return $carLoad;
    }

    public function addItem(CarLoad $carLoad, array $data): CarLoadItem
    {
        if ($carLoad->status === 'UNLOADED') {
            throw new \Exception('Cannot add items to an unloaded car load');
        }

        return DB::transaction(function () use ($carLoad, $data) {
            return $carLoad->items()->create([
                'product_id' => $data['product_id'],
                'quantity_loaded' => $data['quantity_loaded'],
                'comment' => $data['comment'] ?? null,
            ]);
        });
    }

    public function updateItem(CarLoadItem $item, array $data): CarLoadItem
    {
        if ($item->carLoad->status === 'UNLOADED') {
            throw new \Exception('Cannot update items of an unloaded car load');
        }

        return DB::transaction(function () use ($item, $data) {
            $item->update([
                'quantity_loaded' => $data['quantity_loaded'],
                'comment' => $data['comment'] ?? null,
            ]);
            //TODO check the difference after update on warehouse stock update it accordingly
            if (isset($data["quantity_left"])){
                $item->quantity_left = $data["quantity_left"];
                $item->save();
            }

            return $item;
        });
    }

    public function deleteItem(CarLoadItem $item)
    {
        if ($item->carLoad->returned) {
            throw new \Exception('Cannot delete items from an unloaded car load');
        }
        return DB::transaction(function () use ($item) {
            $quantity_left = $item->quantity_left;

            // increment stock
            $product = Product::findOrFail($item->product_id);
            $product->incrementStock($quantity_left, updateMainStock: true);
            $product->save();
//             dd($quantity_left, $product->stock_available);
//             throw new \Exception('Cannot delete items from an unloaded car load');
            $item->delete();

        });
    }

    public function activateCarLoad(CarLoad $carLoad): CarLoad
    {
        if ($carLoad->status !== 'LOADING') {
            throw new \Exception('Only loading car loads can be activated');
        }

        if ($carLoad->items()->count() === 0) {
            throw new \Exception('Cannot activate a car load without items');
        }

        return DB::transaction(function () use ($carLoad) {
            $carLoad->update([
                'status' => 'ACTIVE',
                'load_date' => Carbon::now(),
            ]);

            return $carLoad;
        });
    }

    public function unloadCarLoad(CarLoad $carLoad): CarLoad
    {
        if ($carLoad->status !== 'ACTIVE') {
            throw new \Exception('Only active car loads can be unloaded');
        }

        return DB::transaction(function () use ($carLoad) {
            $carLoad->update([
                'status' => 'UNLOADED',
                'return_date' => Carbon::now(),
            ]);

            return $carLoad;
        });
    }

    public function createFromPrevious(CarLoad $previousCarLoad): CarLoad
    {
        if ($previousCarLoad->status !== 'UNLOADED') {
            throw new \Exception('Can only create new car load from unloaded car loads');
        }

        return DB::transaction(function () use ($previousCarLoad) {
            $newCarLoad = CarLoad::create([
                'name' => $previousCarLoad->name . ' (Copy)',
                'team_id' => $previousCarLoad->team_id,
                'status' => 'LOADING',
                'load_date' => Carbon::now(),
                'previous_car_load_id' => $previousCarLoad->id,
            ]);

            foreach ($previousCarLoad->items as $item) {
                $newCarLoad->items()->create([
                    'product_id' => $item->product_id,
                    'quantity_loaded' => $item->quantity_loaded,
                    'comment' => $item->comment,
                ]);
            }

            return $newCarLoad;
        });
    }

    public function getCarLoadsByTeam(int $teamId)
    {
        return CarLoad::where('team_id', $teamId)
            ->with(['items.product', 'team'])
            ->orderBy('created_at', 'desc')
            ->paginate(100);
    }

    public function getAllCarLoads()
    {
        return CarLoad::with([
            'items' => function($query) {
                $query->orderBy('loaded_at', 'desc');
            },
            'items.product',
            'team',
            'inventory.items.product'
        ])
            ->orderBy('created_at', 'desc')
            ->paginate(1000);
    }
    public function getCurrentCarLoad()
    {
        return CarLoad::with([
            'items' => function($query) {
                $query->orderBy('loaded_at', 'desc');
                $query->orderBy('id', 'desc');
            },
            'items.product',
            'team',
            'inventory.items' => function($query) {
                $query->join('products', 'car_load_inventory_items.product_id', '=', 'products.id')
                    ->orderBy('products.name') // secondary sort for products with same parent
                    ->orderBy('products.parent_id')
                    ->select('car_load_inventory_items.*'); // important: select only inventory_items columns
            },
            'inventory.items.product'
        ])
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->paginate(3);
    }

    public function getCurrentCarLoadItems()
    {
        // TO
        $currentCarLoad = CarLoad::where('team_id', request()->user()->commercial->team_id)
            // check that the car laod is not past
            ->whereDate('return_date', '>', now()->toDateString())
            ->where('returned', false)
            ->with(['items.product'])
            ->first();

//        $currentCarLoad = CarLoad::
//            with(['items.product'])
//            ->latest()
//            ->first();

        if (!$currentCarLoad) {
            return [];
        }

        return $currentCarLoad->items->map(function (CarLoadItem $item) {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'quantity_loaded' => $item->quantity_loaded,
                "quantity_left" => $item->quantity_left,
                "is_base_product" => $item->product->is_base_product,
                "base_quantity" => $item->product->base_quantity,
                'created_at' => $item->loaded_at ? $item->loaded_at->format("Y-m-d H:i:s") : $item->created_at->format
                ('Y-m-d H:i:s')
            ];
        });
    }

    /**
     * @throws \Exception
     */
    public function createFromInventory(CarLoadInventory $inventory): CarLoad
    {
        if (!$inventory->closed) {
            throw new \Exception('Impossible de créer un nouveau chargement à partir d\'un inventaire non fermé');
        }
        // if there is a car load with the same team and the return date is in the future and the car load is not returned, throw an exception
        $carLoad = CarLoad::where('team_id', $inventory->carLoad->team_id)
            ->where('return_date', '>', now())
            ->where("returned", false)
            ->exists();
        if ($carLoad) {
            throw new \Exception('Cette équipe a déjà un chargement en cours et non retourné');
        }

        $carLoad = $inventory->carLoad;

        return DB::transaction(function () use ($carLoad, $inventory) {
            $newCarLoad = CarLoad::create([
                'name' => "Crée à partir de {$inventory->name}",
                'team_id' => $carLoad->team_id,
                'status' => 'LOADING',
                'load_date' => Carbon::now(),
                'return_date'=> Carbon::now()->endOfWeek(),
                'comment' => "Créé à partir de l'inventaire #{$inventory->id}",
                'previous_car_load_id' => $carLoad->id,
            ]);

            // Create items based on inventory differences
            foreach ($inventory->items as $item) {
                $remainingQuantity = $item->total_returned;

                if ($remainingQuantity > 0) {
                    $newCarLoad->items()->create([
                        'product_id' => $item->product_id,
                        'quantity_loaded' => $remainingQuantity,
                        "quantity_left" => $remainingQuantity,
                        "from_previous_car_load" => true,
                        'loaded_at' => Carbon::now()->toDateString(),
                    ]);
                }
            }
            // get missing items from the inventory by distinct product_id and sum the total_loaded


            return $newCarLoad;
        });
    }

    public function transformToVariants(Product $product, array $data): void
    {
        // Get current car load for the team
        $currentCarLoad = CarLoad::where('team_id', request()->user()->commercial->team_id)
            ->whereDate('return_date', '>', now()->toDateString())
            ->where('returned', false)
            ->first();

        if (!$currentCarLoad) {
            throw new \Exception('Aucun chargement actif trouvé pour votre équipe');
        }

        // Find the parent product in car load items
        $parentItem = $currentCarLoad->items()
            ->where('product_id', $product->id)
            ->first();


        if (!$parentItem) {
            throw new \Exception('Ce produit n\'est pas dans votre chargement');
        }
        $product = $parentItem->product;

        if ($currentCarLoad->getTotalQuantityLeftOfProduct($product) < $data['quantityOfBaseProductToTransform']) {
            throw new \Exception('Stock insuffisant dans le chargement. Stock disponible: ' . $currentCarLoad->getTotalQuantityLeftOfProduct($product));
        }

        DB::transaction(function () use ($currentCarLoad, $product, $data, $parentItem) {
            // Decrease parent stock
            $currentCarLoad->decreaseStockOfProduct($product, $data['quantityOfBaseProductToTransform']);

            // Process each variant
            foreach ($data['items'] as $item) {
                $variant = Product::findOrFail($item['product_id']);

                // Verify this is a valid parent-child relationship
                if ($variant->parent_id !== $product->id) {
                    throw new \Exception('Le produit ' . $variant->name . ' n\'est pas un variant de ce produit');
                }

                // Calculate actual quantity after removing unused quantity
                $actualQuantity = $item['quantity'] - $item['unused_quantity'];
                if ($actualQuantity <= 0) continue;

                // Create car load item for variant
                $currentCarLoad->items()->create([
                    'product_id' => $variant->id,
                    'quantity_loaded' => $actualQuantity,
                    'quantity_left' => $actualQuantity,
                    'loaded_at' => now(),
                    'comment' => 'Transformé à partir de ' . $product->name
                ]);
            }
        });
    }

    public function createItems(CarLoad $carLoad, array $items) : CarLoad
    {
        return DB::transaction(function () use ($items, $carLoad) {
            foreach ($items as $item) {
                $carLoad->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity_loaded' => $item['quantity_loaded'],
                    'quantity_left' => $item['quantity_left'],
                    'loaded_at' => $item['loaded_at'] ?? now()->toDateString(),
                    'comment' => $item['comment'] ?? null,
                ]);
                $product = Product::find($item['product_id']);
                $product->decrementStock($item['quantity_loaded'], updateMainStock: true);
            }
            return $carLoad;
        });
    }

    public function determineTotalSoldOfAParentProductFromChildren(CarLoad $carLoad, Product $parentProduct)
    {

        // Get all variants (children) of the parent product
        $children = Product::select('id')
            ->where('parent_id', $parentProduct->id)->get();

        // Initialize total sold for parent (starting at 0)
        $totalSoldOfParent = $carLoad->inventory->items()->where('product_id', $parentProduct->id)->sum('total_sold');
        $startDate = $carLoad->load_date->toDateTime();
        $endDate = $carLoad->return_date->toDateTime();
        $productIds = $children->pluck('id')->toArray();

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        // TODO find a way to link ventes with car load
        $salesByProduct = DB::select("
                                        SELECT 
                                            product_id,
                                            SUM(quantity) AS total_quantity_sold
                                        FROM ventes
                                        WHERE created_at BETWEEN ? AND ?
                                        AND product_id IN ($placeholders)
                                        GROUP BY product_id
                                        ORDER BY total_quantity_sold DESC
                                    ", array_merge([$startDate, $endDate], $productIds));
//        $salesByProductMap = collect($salesByProduct)->keyBy('product_id');
        // For each variant, sum quantity from ventes between the car load dates
        foreach ($salesByProduct as $child) {
            // Sum quantity column from ventes between the car load dates
            $totalSoldFromVentes = $child->total_quantity_sold;
            // Skip if no quantity was sold
            if ($totalSoldFromVentes <= 0) {
                continue;
            }

            // Convert the variant quantity sold to parent quantity equivalent
            $childProduct = Product::findOrFail($child->product_id);
            $conversionResult = $childProduct->convertQuantityToParentQuantity($totalSoldFromVentes);

            // Add the converted quantity to total sold of parent (using decimal for precision)
            $totalSoldOfParent += $conversionResult['decimal_parent_quantity'];
        }
        // Return the total sold of parent product equivalent
        return $totalSoldOfParent;
    }
    #[ArrayShape(["loadingsHistory" => "array", "product" => "array", "ventes" => "array"])]
    public function productHistoryInCarLoad(Product $product, CarLoad $carLoad)
    {
        $ventes = Vente::where('product_id', $product->id)
            ->join('sales_invoices', 'sales_invoices.id', '=', 'ventes.sales_invoice_id')
            ->join('customers', 'customers.id', '=', 'sales_invoices.customer_id')
            ->select('product_id','quantity','price','customers.name','ventes.created_at','sales_invoices.created_at as invoice_date','sales_invoices.id as invoice_number')
            ->whereDate('ventes.created_at', ">=", $carLoad->load_date->toDateString())
            ->orderBy('ventes.created_at', 'desc')
            ->orderBy('sales_invoices.customer_id', )
            ->orderBy('sales_invoices.created_at', 'desc')
            ->whereDate('ventes.created_at', "<=", $carLoad->return_date->toDateString())
            ->get();
        $loadingsHistory = $carLoad->items()->where('product_id', $product->id)->get();
//        $loadingsHistory = [];
//        $ventes = [];

        return  [
            'loadingsHistory' => $loadingsHistory,
            'product' => $product,
            'ventes' => $ventes,

        ];
    }

    public function getCurrentCarLoadForTeam(Team $team): ?CarLoad
    {
        return CarLoad::where('team_id', $team->id)
            ->where('return_date',">=", now())
            ->orderBy('return_date', 'desc')
            ->limit(1)
            ->first();
    }


} 