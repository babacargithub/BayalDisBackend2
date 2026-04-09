<?php

namespace App\Services;

use App\Data\CarLoadInventory\CarLoadInventoryResultItemDTO;
use App\Data\CarLoadInventory\ConvertedQuantityDTO;
use App\Data\CarLoadInventory\InventoryParentProductDTO;
use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use App\Models\CarLoadInventoryItem;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Models\Team;
use App\Models\Vente;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use JetBrains\PhpStorm\ArrayShape;
use Throwable;

class CarLoadService
{
    public function createCarLoad(array $data): CarLoad
    {
        $carLoad = CarLoad::create([
            'name' => $data['name'],
            'team_id' => $data['team_id'],
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'comment' => $data['comment'] ?? null,
            'return_date' => $data['return_date'],
            'status' => CarLoadStatus::Loading,
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
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'comment' => $data['comment'] ?? null,
            'return_date' => $data['return_date'],
        ]);

        return $carLoad;
    }

    /**
     * @throws Throwable
     */
    public function addItem(CarLoad $carLoad, array $data): CarLoadItem
    {
        if ($carLoad->status !== CarLoadStatus::Loading && $carLoad->status !== CarLoadStatus::Selling) {
            throw new Exception('Items can only be added to a car load in LOADING status');
        }

        return DB::transaction(function () use ($carLoad, $data) {
            return $carLoad->items()->create([
                'product_id' => $data['product_id'],
                'quantity_loaded' => $data['quantity_loaded'],
                'quantity_left' => $data['quantity_loaded'],
                'comment' => $data['comment'] ?? null,
                'source' => CarLoadItemSource::Warehouse,
                'loaded_at' => now(),
            ]);
        });
    }

    public function updateItem(CarLoadItem $item, array $data): CarLoadItem
    {
        if ($item->carLoad->status !== CarLoadStatus::Loading) {
            throw new Exception('Items can only be updated on a car load in LOADING status');
        }

        return DB::transaction(function () use ($item, $data) {
            $item->update([
                'quantity_loaded' => $data['quantity_loaded'],
                'comment' => $data['comment'] ?? null,
            ]);
            if (isset($data['quantity_left'])) {
                $item->quantity_left = $data['quantity_left'];
                $item->save();
            }

            return $item;
        });
    }

    public function deleteItem(CarLoadItem $item)
    {
        if ($item->carLoad->status->isTerminal()) {
            throw new Exception('Cannot delete items from a terminated car load');
        }

        return DB::transaction(function () use ($item) {
            // Only restore warehouse stock for items that were originally loaded from the warehouse.
            // Items rolled over from a previous car load (FromPreviousCarLoad) or created by
            // transformation (TransformedFromParent) never consumed warehouse stock, so restoring
            // them would create phantom warehouse inventory.
            if ($item->source === CarLoadItemSource::Warehouse) {
                $product = Product::findOrFail($item->product_id);
                $product->incrementStock($item->quantity_left, updateMainStock: true);
                $product->save();
            }

            $item->delete();
        });
    }

    /**
     * @throws Throwable
     */
    public function activateCarLoad(CarLoad $carLoad): CarLoad
    {
        if ($carLoad->status !== CarLoadStatus::Loading) {
            throw new Exception('Only car loads in LOADING status can be activated');
        }

        if ($carLoad->items()->count() === 0) {
            throw new Exception('Cannot activate a car load without items');
        }

        return DB::transaction(function () use ($carLoad) {
            $carLoad->update([
                'status' => CarLoadStatus::Selling,
                'load_date' => Carbon::now(),
            ]);

            return $carLoad;
        });
    }

    public function unloadCarLoad(CarLoad $carLoad): CarLoad
    {
        if ($carLoad->status !== CarLoadStatus::Selling) {
            throw new Exception('Only car loads in SELLING status can be directly unloaded');
        }

        return DB::transaction(function () use ($carLoad) {
            $carLoad->update([
                'status' => CarLoadStatus::TerminatedAndTransferred,
                'return_date' => Carbon::now(),
            ]);

            return $carLoad;
        });
    }

    /**
     * @throws Throwable
     */
    public function getCarLoadsByTeam(int $teamId)
    {
        return CarLoad::where('team_id', $teamId)
            ->with(['items.product', 'team'])
            ->orderBy('created_at', 'desc')
            ->paginate(100);
    }

    public function getAllCarLoads()
    {
        return CarLoad::with(['team', 'inventory', 'vehicle'])
            ->orderBy('created_at', 'desc')
            ->paginate(10000);
    }

    public function getCurrentCarLoad()
    {
        return CarLoad::with([
            'items' => function ($query) {
                $query->orderBy('loaded_at', 'desc');
                $query->orderBy('id', 'desc');
            },
            'items.product',
            'team',
            'inventory.items' => function ($query) {
                $query->join('products', 'car_load_inventory_items.product_id', '=', 'products.id')
                    ->orderBy('products.name') // secondary sort for products with same parent
                    ->orderBy('products.parent_id')
                    ->select('car_load_inventory_items.*'); // important: select only inventory_items columns
            },
            'inventory.items.product',
        ])
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->paginate(3);
    }

    public function getCurrentCarLoadItems(Team $team)
    {
        $currentCarLoad = CarLoad::where('team_id', $team->id)
            ->whereDate('return_date', '>', now()->toDateString())
            ->where('returned', false)
            ->with(['items.product'])
            ->first();

        //        $currentCarLoad = CarLoad::
        //            with(['items.product'])
        //            ->latest()
        //            ->first();

        if (! $currentCarLoad) {
            return [];
        }

        return $currentCarLoad->items->map(function (CarLoadItem $item) {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'quantity_loaded' => $item->quantity_loaded,
                'quantity_left' => $item->quantity_left,
                'is_base_product' => $item->product->is_base_product,
                'base_quantity' => $item->product->base_quantity,
                'created_at' => $item->loaded_at ? $item->loaded_at->format('Y-m-d H:i:s') : $item->created_at->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function createFromInventory(CarLoadInventory $inventory): CarLoad
    {
        if (! $inventory->closed) {
            throw new Exception('Impossible de créer un nouveau chargement à partir d\'un inventaire non fermé');
        }
        // if there is a car load with the same team and the return date is in the future and the car load is not returned, throw an exception
        $carLoad = CarLoad::where('team_id', $inventory->carLoad->team_id)
            ->where('return_date', '>', now())
            ->where('returned', false)
            ->exists();
        if ($carLoad) {
            throw new Exception('Cette équipe a déjà un chargement en cours et non retourné');
        }

        $carLoad = $inventory->carLoad;

        return DB::transaction(function () use ($carLoad, $inventory) {
            $carLoad->update(['status' => CarLoadStatus::TerminatedAndTransferred]);

            $newCarLoad = CarLoad::create([
                'name' => "Crée à partir de {$inventory->name}",
                'team_id' => $carLoad->team_id,
                'status' => CarLoadStatus::Loading,
                'load_date' => Carbon::now(),
                'return_date' => Carbon::now()->endOfWeek(),
                'comment' => "Créé à partir de l'inventaire #{$inventory->id}",
                'previous_car_load_id' => $carLoad->id,
                'vehicle_id' => $carLoad->vehicle_id,
            ]);

            // Create items based on inventory return quantities.
            // Cost price is computed via FIFO-weighted average over the batches still
            // in the previous car load so the new car load inherits the correct cost basis.
            foreach ($inventory->items as $item) {
                $remainingQuantity = $item->total_returned;

                if ($remainingQuantity > 0) {
                    $costPricePerUnit = $this->computeFifoWeightedCostPriceForQuantityInCarLoad(
                        $item->product,
                        $remainingQuantity,
                        $carLoad
                    );

                    $newCarLoad->items()->create([
                        'product_id' => $item->product_id,
                        'quantity_loaded' => $remainingQuantity,
                        'quantity_left' => $remainingQuantity,
                        'cost_price_per_unit' => $costPricePerUnit,
                        'source' => CarLoadItemSource::FromPreviousCarLoad,
                        'from_previous_car_load_id' => $carLoad->id,
                        'loaded_at' => Carbon::now()->toDateString(),
                    ]);
                }
            }

            // Zero out quantity_left on all old car load items.
            // The remaining stock has been physically transferred to the new car load.
            // Leaving old items non-zero creates phantom inventory across car loads.
            $carLoad->items()->update(['quantity_left' => 0]);

            return $newCarLoad;
        });
    }

    /**
     * @throws Throwable
     */
    public function transformToVariants(Product $product, array $data, CarLoad $carLoad): void
    {
        if ($data['quantityOfBaseProductToTransform'] <= 0) {
            throw new Exception('La quantité à transformer doit être supérieure à zéro.');
        }

        // Find the parent product in car load items
        $parentItem = $carLoad->items()
            ->where('product_id', $product->id)
            ->first();

        if (! $parentItem) {
            throw new Exception('Ce produit n\'est pas dans votre chargement');
        }
        $product = $parentItem->product;

        $quantityAvailableInCarLoad = $this->getTotalQuantityLeftOfProductInCarLoad($carLoad, $product);
        if ($quantityAvailableInCarLoad < $data['quantityOfBaseProductToTransform']) {
            throw new Exception('Stock insuffisant dans le chargement. Stock disponible: '.$quantityAvailableInCarLoad);
        }

        // Compute the FIFO-weighted cost of the parent carton batches being consumed
        // BEFORE decrementing stock, so we can snapshot the cost onto the variant items.
        $parentCostPerCarton = $this->computeFifoWeightedCostPriceForQuantityInCarLoad(
            $product,
            $data['quantityOfBaseProductToTransform'],
            $carLoad
        );

        DB::transaction(function () use ($carLoad, $product, $data, $parentCostPerCarton) {
            // Decrease parent stock
            $this->decreaseProductStockInCarLoadUsingFifo($product, $data['quantityOfBaseProductToTransform'], $carLoad);

            // Process each variant
            foreach ($data['items'] as $item) {
                $variant = Product::findOrFail($item['product_id']);

                // Verify this is a valid parent-child relationship
                if ($variant->parent_id !== $product->id) {
                    throw new Exception('Le produit '.$variant->name.' n\'est pas un variant de ce produit');
                }

                // Calculate actual quantity after removing unused quantity
                $actualQuantity = $item['quantity'] - $item['unused_quantity'];
                if ($actualQuantity <= 0) {
                    continue;
                }

                // Compute cost_price_per_unit for this variant:
                //   base = parent_cost_per_carton / (carton_base_quantity / variant_base_quantity)
                //   final = base + variant.packaging_cost  (plastic bag cost per paquet)
                $variantCostPricePerUnit = null;
                if ($parentCostPerCarton !== null && $product->base_quantity > 0 && $variant->base_quantity > 0) {
                    $paquetsPerCarton = $product->base_quantity / $variant->base_quantity;
                    $baseCostPerVariant = (int) round($parentCostPerCarton / $paquetsPerCarton);
                    $variantCostPricePerUnit = $baseCostPerVariant + $variant->packaging_cost;
                }

                // Create car load item for variant — source is TransformedFromParent because
                // the stock came from the parent product in this car load, not from the warehouse.
                $carLoad->items()->create([
                    'product_id' => $variant->id,
                    'quantity_loaded' => $actualQuantity,
                    'quantity_left' => $actualQuantity,
                    'cost_price_per_unit' => $variantCostPricePerUnit,
                    'loaded_at' => now(),
                    'comment' => 'Transformé à partir de '.$product->name,
                    'source' => CarLoadItemSource::TransformedFromParent,
                ]);
            }
        });
    }

    /**
     * Load items from the warehouse into a car load, consuming StockEntry batches in FIFO order
     * and locking cost_price_per_unit on each CarLoadItem at load time.
     *
     * When a single quantity spans multiple StockEntry batches, multiple CarLoadItem rows are
     * created (one per batch), each carrying its own cost_price_per_unit.
     *
     * @param  bool  $decrementWarehouseStock  Pass false when the caller has already created
     *                                         StockEntry rows and does not want warehouse stock
     *                                         decremented again (e.g. PurchaseInvoice put-in-stock).
     *
     * @throws Throwable
     */
    public function createItemsToCarLoad(CarLoad $carLoad, array $items, bool $decrementWarehouseStock = true): CarLoad
    {
        return DB::transaction(function () use ($items, $carLoad, $decrementWarehouseStock) {
            $productService = app(ProductService::class);

            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $quantityLoaded = $item['quantity_loaded'];
                $loadedAt = $item['loaded_at'] ?? now()->toDateString();
                $comment = $item['comment'] ?? null;

                if ($decrementWarehouseStock) {
                    // Consume warehouse stock in FIFO order and create one CarLoadItem per batch,
                    // each carrying the exact cost locked at load time.
                    $consumedBatches = $productService->consumeWarehouseStockInFifoReturningBatchCosts(
                        $product,
                        $quantityLoaded
                    );

                    foreach ($consumedBatches as $batch) {
                        $carLoad->items()->create([
                            'product_id' => $product->id,
                            'quantity_loaded' => $batch['quantity'],
                            'quantity_left' => $batch['quantity'],
                            'cost_price_per_unit' => $batch['cost_price_per_unit'],
                            'loaded_at' => $loadedAt,
                            'comment' => $comment,
                            'source' => CarLoadItemSource::Warehouse,
                        ]);
                    }
                } else {
                    // Caller manages stock externally; create a single item without cost tracking.
                    $carLoad->items()->create([
                        'product_id' => $product->id,
                        'quantity_loaded' => $quantityLoaded,
                        'quantity_left' => $item['quantity_left'] ?? $quantityLoaded,
                        'cost_price_per_unit' => null,
                        'loaded_at' => $loadedAt,
                        'comment' => $comment,
                        'source' => CarLoadItemSource::Warehouse,
                    ]);
                }
            }

            return $carLoad;
        });
    }

    public function determineTotalSoldOfAParentProductFromChildren(CarLoad $carLoad, Product $parentProduct)
    {

        // Get all variants (children) of the parent product
        $children = Product::select('id')
            ->where('parent_id', $parentProduct->id)->get();

        $totalSoldOfParent = 0;
        $productIds = $children->pluck('id')->toArray();

        if (empty($productIds)) {
            return $totalSoldOfParent;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        // Join through sales_invoices to filter by car_load_id precisely.
        // Date-range filtering was unreliable when car loads overlap in time.
        $salesByProduct = DB::select("
            SELECT
                v.product_id,
                SUM(v.quantity) AS total_quantity_sold
            FROM ventes v
            INNER JOIN sales_invoices si ON si.id = v.sales_invoice_id
            WHERE si.car_load_id = ?
            AND v.product_id IN ($placeholders)
            GROUP BY v.product_id
        ", array_merge([$carLoad->id], $productIds));
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

    #[ArrayShape(['loadingsHistory' => 'array', 'product' => 'array', 'ventes' => 'array'])]
    public function productHistoryInCarLoad(Product $product, CarLoad $carLoad): array
    {
        // Filter by car_load_id via the sales_invoices join — date-range filtering is
        // unreliable when car loads overlap in time (same as determineTotalSoldOfAParentProductFromChildren).
        $ventes = Vente::where('ventes.product_id', $product->id)
            ->join('sales_invoices', 'sales_invoices.id', '=', 'ventes.sales_invoice_id')
            ->join('customers', 'customers.id', '=', 'sales_invoices.customer_id')
            ->where('sales_invoices.car_load_id', $carLoad->id)
            ->select('ventes.product_id', 'ventes.quantity', 'ventes.price', 'customers.name', 'ventes.created_at', 'sales_invoices.created_at as invoice_date', 'sales_invoices.id as invoice_number')
            ->orderBy('ventes.created_at', 'desc')
            ->orderBy('sales_invoices.customer_id')
            ->orderBy('sales_invoices.created_at', 'desc')
            ->get();
        $loadingsHistory = $carLoad->items()->where('product_id', $product->id)->get();
        //        $loadingsHistory = [];
        //        $ventes = [];

        return [
            'loadingsHistory' => $loadingsHistory,
            'product' => $product,
            'ventes' => $ventes,

        ];
    }

    public function getCurrentCarLoadForTeam(Team $team): ?CarLoad
    {
        return CarLoad::where('team_id', $team->id)
            ->whereDate('return_date', '>=', now()->toDateString())
            ->orderBy('return_date', 'desc')
            ->limit(1)
            ->first();
    }

    /**
     * Find the team's currently in-progress car load by status.
     *
     * "In-progress" covers Loading (being stocked), Selling (in the field),
     * and OngoingInventory (end-of-cycle count). Loading is included because
     * daily fixed costs (warehouse, overhead) accrue from the moment a car
     * load is created — not only once it starts selling.
     *
     * Returns null when the team has no car load in any of these statuses
     * (e.g. between cycles where all car loads are terminated).
     */
    public function findInProgressCarLoadForTeam(Team $team): ?CarLoad
    {
        return CarLoad::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [CarLoadStatus::Loading, CarLoadStatus::Selling, CarLoadStatus::OngoingInventory])
            ->with('vehicle')
            ->orderByDesc('id')
            ->first();
    }

    private function convertQuantity(Product $product, float|int $quantity): ConvertedQuantityDTO
    {
        $convertedDisplay = $product->getFormattedDisplayOfCartonAndParquets($quantity);

        return new ConvertedQuantityDTO(parentQuantity: $convertedDisplay['cartons'], childQuantity: $convertedDisplay['paquets'], childName: $convertedDisplay['first_variant_name']);

    }

    #[ArrayShape(['items' => 'array'])]
    public function getCalculatedQuantitiesOfProductsInInventory(CarLoad $carLoad, CarLoadInventory $inventory): array
    {
        // Ensure required relations are loaded
        $inventory->loadMissing(['items.product', 'carLoad.team', 'user']);

        // Resolve only the parent products that are actually represented in this inventory
        // (either as the parent itself or via a variant child), rather than loading the
        // entire product catalog. This prevents unrelated products from appearing and avoids
        // unnecessary memory usage for large catalogs.
        $inventoryProductIds = $inventory->items->pluck('product_id');

        $directParentIds = Product::whereIn('id', $inventoryProductIds)
            ->whereNull('parent_id')
            ->pluck('id');

        $parentIdsFromVariants = Product::whereIn('id', $inventoryProductIds)
            ->whereNotNull('parent_id')
            ->pluck('parent_id');

        $parentProducts = Product::whereIn('id', $directParentIds->merge($parentIdsFromVariants)->unique())->get();

        // Build items with all computed fields needed by the Blade view
        $processedItems = $parentProducts->map(function (Product $parentProduct) use ($carLoad, $inventory) {
            // Find the inventory item for the parent (if any)
            $parentItem = $inventory->items->firstWhere('product_id', $parentProduct->id);

            // Find all child inventory items for this parent
            $childrenItems = $inventory->items->filter(function ($invItem) use ($parentProduct) {
                return optional($invItem->product)->parent_id === $parentProduct->id;
            });

            // Total sold in parent units across children
            $calculatedTotalSold = $parentItem?->total_sold ?? 0;

            foreach ($childrenItems as $childItem) {
                /** @var CarLoadInventoryItem $childItem */
                $calculatedTotalSold += $childItem->product
                    ->convertQuantityToParentQuantity($childItem->total_sold)['decimal_parent_quantity'];
            }

            // Loaded including previous car load items of children converted to parent units.
            // Only Warehouse and FromPreviousCarLoad items count as "loaded" — TransformedFromParent
            // items must be excluded because their stock was already consumed from the parent
            // product's car load stock, so counting them again would double-count.
            $calculatedTotalLoaded = $parentItem?->total_loaded ?? 0;
            $childItemsCountingAsLoaded = $carLoad->items()
                ->join('products', 'products.id', '=', 'car_load_items.product_id')
                ->where('products.parent_id', $parentProduct->id)
                ->where('source', CarLoadItemSource::FromPreviousCarLoad->value)
                ->get();

            foreach ($childItemsCountingAsLoaded as $childItem) {
                $calculatedTotalLoaded += $childItem->product
                    ->convertQuantityToParentQuantity($childItem->quantity_loaded)['decimal_parent_quantity'];
            }
            // Children (variants) within this inventory for display of returns
            $children = CarLoadInventoryItem::join('products', 'car_load_inventory_items.product_id', '=', 'products.id')
                ->where('products.parent_id', $parentProduct->id)
                ->where('car_load_inventory_items.car_load_inventory_id', $inventory->id)
                ->get();

            // Total returned in parent units (parent returns + children returns converted)
            $calculatedTotalReturnedParent = $parentItem?->total_returned ?? 0;
            foreach ($children as $childItem) {
                /** @var CarLoadInventoryItem $childItem */
                $calculatedTotalReturnedParent += $childItem->product
                    ->convertQuantityToParentQuantity($childItem->total_returned)['decimal_parent_quantity'];
            }
            // Result in parent decimal units
            $resultDecimal = $calculatedTotalSold + $calculatedTotalReturnedParent - $calculatedTotalLoaded;

            // Compute monetary value using the variant unit price × result in variant units.
            // If no variant exists, fall back to the parent price × decimal parent quantity.
            $firstVariant = $parentProduct->variants()->first();
            if ($firstVariant && $firstVariant->base_quantity > 0 && $parentProduct->base_quantity > 0) {
                $paquetsPerCarton = $parentProduct->base_quantity / $firstVariant->base_quantity;
                $resultInVariantUnits = $resultDecimal * $paquetsPerCarton;
                $priceOfResultComputation = (int) round($firstVariant->price * $resultInVariantUnits);
            } else {
                $priceOfResultComputation = (int) round($parentProduct->price * $resultDecimal);
            }

            return new CarLoadInventoryResultItemDTO(
                parent: InventoryParentProductDTO::fromProduct($parentProduct),
                totalLoaded: $calculatedTotalLoaded,
                totalReturned: $calculatedTotalReturnedParent,
                totalSold: $calculatedTotalSold,
                children: $children->map(fn ($item) => InventoryParentProductDTO::fromProduct($item->product)),
                totalLoadedConverted: $this->convertQuantity($parentProduct, $calculatedTotalLoaded),
                totalSoldConverted: $this->convertQuantity($parentProduct, $calculatedTotalSold),
                totalReturnedConverted: $this->convertQuantity($parentProduct, $calculatedTotalReturnedParent),
                resultConverted: $this->convertQuantity($parentProduct, $resultDecimal),
                resultOfComputation: $resultDecimal,
                priceOfResultComputation: $priceOfResultComputation,
            );
        })->filter()->values();

        return ['items' => $processedItems];
    }

    public function getTotalQuantityLeftOfProductInCarLoad(CarLoad $carLoad, Product $product): int
    {
        return $carLoad->items()->where('product_id', $product->id)->sum('quantity_left');
    }

    /**
     * Simulate FIFO consumption of a given quantity from a product's CarLoadItems and return
     * the weighted average cost_price_per_unit of the batches that would be consumed.
     *
     * This does NOT modify any data — it is a read-only cost snapshot used before calling
     * decreaseProductStockInCarLoadUsingFifo() to know the cost basis of the items being consumed.
     *
     * Returns null if any of the consumed batches has no cost_price_per_unit set (legacy item).
     */
    public function computeFifoWeightedCostPriceForQuantityInCarLoad(
        Product $product,
        int $quantityToConsume,
        CarLoad $carLoad
    ): ?int {
        $carLoadItemsOrderedByOldestFirst = $carLoad->items()
            ->where('product_id', $product->id)
            ->where('quantity_left', '>', 0)
            ->orderByRaw('(loaded_at IS NULL) ASC, loaded_at ASC')
            ->get();

        $remainingQuantityToConsume = $quantityToConsume;
        $weightedCostTotal = 0;
        $totalQuantityAccounted = 0;

        foreach ($carLoadItemsOrderedByOldestFirst as $carLoadItem) {
            if ($remainingQuantityToConsume <= 0) {
                break;
            }

            if ($carLoadItem->cost_price_per_unit === null) {
                // A legacy item with no cost data — cannot produce a reliable cost.
                return null;
            }

            $quantityFromThisItem = min($carLoadItem->quantity_left, $remainingQuantityToConsume);
            $weightedCostTotal += $quantityFromThisItem * $carLoadItem->cost_price_per_unit;
            $totalQuantityAccounted += $quantityFromThisItem;
            $remainingQuantityToConsume -= $quantityFromThisItem;
        }

        if ($totalQuantityAccounted === 0) {
            return null;
        }

        return (int) round($weightedCostTotal / $totalQuantityAccounted);
    }

    public function getTotalQuantityLoadedOfProductInCarLoad(CarLoad $carLoad, Product $product): int
    {
        return $carLoad->items()->where('product_id', $product->id)->sum('quantity_loaded');
    }

    /**
     * @throws InsufficientStockException
     * @throws Throwable
     */
    public function decreaseProductStockInCarLoadUsingFifo(Product $product, int $quantity, CarLoad $carLoad): void
    {
        DB::transaction(function () use ($product, $quantity, $carLoad): void {
            // Lock rows for update to prevent concurrent overselling race conditions.
            // NULL loaded_at rows are sorted last so they don't incorrectly consume FIFO first.
            $itemsOrderedByOldestLoadedFirst = $carLoad->items()
                ->where('product_id', $product->id)
                ->orderByRaw('(loaded_at IS NULL) ASC, loaded_at ASC')
                ->lockForUpdate()
                ->get();

            $totalQuantityLeft = $itemsOrderedByOldestLoadedFirst->sum('quantity_left');

            if ($totalQuantityLeft < $quantity) {
                throw new InsufficientStockException(
                    'Stock insuffisant pour le produit '.$product->name.
                    ' dans le chargement '.$carLoad->name.
                    '. Qté restante : '.$totalQuantityLeft
                );
            }

            $remainingQuantityToDeduct = $quantity;

            foreach ($itemsOrderedByOldestLoadedFirst as $item) {
                if ($item->quantity_left >= $remainingQuantityToDeduct) {
                    $item->quantity_left -= $remainingQuantityToDeduct;
                    $item->save();
                    break;
                }

                $remainingQuantityToDeduct -= $item->quantity_left;
                $item->quantity_left = 0;
                $item->save();
            }
        });
    }

    /**
     * Computes the total cost value of all stock currently remaining in the car load.
     *
     * Value = SUM(quantity_left × product.cost_price) across all items with stock left.
     *
     * Terminated car loads always return 0 — their stock has been physically transferred
     * to the next car load and their quantity_left values are already zeroed.
     *
     * A single JOIN query is used to avoid N+1 product lookups.
     */
    public function calculateCarLoadStockValue(CarLoad $carLoad): int
    {
        if ($carLoad->status === CarLoadStatus::TerminatedAndTransferred) {
            return 0;
        }

        $totalStockValue = (int) DB::table('car_load_items')
            ->join('products', 'products.id', '=', 'car_load_items.product_id')
            ->where('car_load_items.car_load_id', $carLoad->id)
            ->where('car_load_items.quantity_left', '>', 0)
            ->sum(DB::raw('car_load_items.quantity_left * products.cost_price'));

        return $totalStockValue;
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function increaseProductStockInCarLoad(Product $product, int $quantity, CarLoad $carLoad): void
    {
        DB::transaction(function () use ($product, $quantity, $carLoad): void {
            try {
                // NULL loaded_at rows are sorted last so the most recent real batch is picked first.
                $latestItem = $carLoad->items()
                    ->where('product_id', $product->id)
                    ->orderByRaw('(loaded_at IS NULL) ASC, loaded_at DESC')
                    ->lockForUpdate()
                    ->firstOrFail();

                $latestItem->quantity_left += $quantity;
                $latestItem->save();
            } catch (ModelNotFoundException) {
                throw new Exception("Ce produit n'est pas dans ce chargement du véhicule : ".$carLoad->name);
            }
        });
    }
}
