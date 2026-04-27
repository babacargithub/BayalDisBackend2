<?php

namespace App\Services;

use App\Enums\StockEntryTransferType;
use App\Exceptions\InsufficientStockException;
use App\Models\CarLoad; // required for decrementStock routing parameter type
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\StockEntryTransfer;
use Exception;
use Illuminate\Support\Facades\Cache;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

readonly class ProductService
{
    public function __construct(
        private CarLoadService $carLoadService
    ) {}

    public function getProductAvailableStockInWarehouse(Product $product): int
    {
        return $product->stockEntries()->sum('quantity_left');
    }

    public function getProductWarehouseStockValue(Product $product): int
    {
        return $product->stockEntries()
            ->selectRaw('SUM(quantity_left * unit_price) as value')
            ->where('quantity_left', '>', 0)
            ->value('value') ?? 0;
    }

    public function getTotalQuantitySold(Product $product): int
    {
        return $product->ventes()->sum('quantity');
    }

    /**
     * @return array{parent_quantity: int, remaining_variant_quantity: int, decimal_parent_quantity: float}
     */
    #[ArrayShape(['parent_quantity' => 'int', 'remaining_variant_quantity' => 'int', 'decimal_parent_quantity' => 'float'])]
    public function convertVariantQuantityToParentQuantity(Product $variantProduct, int|float $quantity): array
    {
        if ($variantProduct->is_base_product) {
            return [
                'parent_quantity'            => 0,
                'remaining_variant_quantity' => 0,
                'decimal_parent_quantity'    => 0.0,
            ];
        }

        $parentProduct   = $variantProduct->parent;
        $conversionRatio = $parentProduct->base_quantity / $variantProduct->base_quantity;

        $decimalParentQuantity    = $quantity / $conversionRatio;                          // example 4.475
        $parentQuantity           = floor($decimalParentQuantity);                         // 4
        $remainingVariantQuantity = $quantity - ($parentQuantity * $conversionRatio);      // 19

        return [
            'parent_quantity'            => intval($parentQuantity),
            'remaining_variant_quantity' => intval($remainingVariantQuantity),
            'decimal_parent_quantity'    => floatval($decimalParentQuantity),
        ];
    }
    /**
     * @throws Exception
     */
    public function decreaseWarehouseStockUsingFifo(Product $product, int $quantity): void
    {
        $this->consumeWarehouseStockInFifoReturningBatchCosts($product, $quantity);
    }

    /**
     * Consume warehouse stock for a product in FIFO order (oldest StockEntry first),
     * record a StockEntryTransfer (Out) for each batch, recompute quantity_left from
     * the transfer ledger, and return the batches consumed with their locked unit cost.
     *
     * Each entry in the returned array represents one StockEntry batch (or partial batch)
     * consumed during this operation. The stock_entry_transfer_id is included so the caller
     * can link the transfer to the CarLoadItem that was created for it.
     *
     * @return array<int, array{quantity: int, cost_price_per_unit: int, stock_entry_transfer_id: int}>
     *
     * @throws InsufficientStockException
     */
    public function consumeWarehouseStockInFifoReturningBatchCosts(Product $product, int $quantity): array
    {
        $totalAvailableStock = $product->stockEntries()->sum('quantity_left');

        if ($totalAvailableStock < $quantity) {
            throw new InsufficientStockException(
                "Stock insuffisant pour {$product->name}. Stock disponible: {$totalAvailableStock}, Quantité demandée: {$quantity}"
            );
        }

        $stockEntriesOrderedByOldestFirst = $product->stockEntries()
            ->where('quantity_left', '>', 0)
            ->orderBy('created_at', 'asc')
            ->get();

        $remainingQuantityToDeduct = $quantity;
        $consumedBatches = [];

        foreach ($stockEntriesOrderedByOldestFirst as $stockEntry) {
            if ($remainingQuantityToDeduct <= 0) {
                break;
            }

            $quantityConsumedFromThisBatch = min($stockEntry->quantity_left, $remainingQuantityToDeduct);

            $transfer = StockEntryTransfer::create([
                'stock_entry_id' => $stockEntry->id,
                'quantity' => $quantityConsumedFromThisBatch,
                'transfer_type' => StockEntryTransferType::Out,
                'transferred_at' => now(),
            ]);

            // Recompute and persist quantity_left from the transfer ledger,
            // and keep the in-memory model in sync so the next FIFO iteration
            // reads the updated value.
            $stockEntry->updateQuantityLeftFromTransfers();

            $consumedBatches[] = [
                'quantity' => $quantityConsumedFromThisBatch,
                'cost_price_per_unit' => $stockEntry->total_unit_cost,
                'stock_entry_transfer_id' => $transfer->id,
            ];

            $remainingQuantityToDeduct -= $quantityConsumedFromThisBatch;
        }

        return $consumedBatches;
    }

    /**
     * @throws Exception
     */
    public function decrementStock(Product $product, int $quantity, bool $updateMainStock, ?CarLoad $carLoad): void
    {
        if ($carLoad && ! $updateMainStock) {
            $this->carLoadService->decreaseProductStockInCarLoadUsingFifo($product, $quantity, $carLoad);
        } else {
            $this->decreaseWarehouseStockUsingFifo($product, $quantity);
        }
    }

    /**
     * @throws Exception
     */
    public function incrementWarehouseStockOnLatestEntry(Product $product, int $quantity): void
    {
        $latestStockEntry = $product->stockEntries()->latest()->firstOrFail();

        StockEntryTransfer::create([
            'stock_entry_id' => $latestStockEntry->id,
            'quantity' => $quantity,
            'transfer_type' => StockEntryTransferType::In,
            'transferred_at' => now(),
        ]);

        $latestStockEntry->updateQuantityLeftFromTransfers();
    }

    /**
     * @throws Exception
     */
    public function increaseStock(Product $product, int $quantity, bool $updateWarehouseStock): void
    {
        if ($updateWarehouseStock) {
            $this->incrementWarehouseStockOnLatestEntry($product, $quantity);

            return;
        }

        $carLoad = $this->carLoadService->getCurrentCarLoadForTeam(auth()->user()->commercial->team);

        if ($carLoad === null) {
            throw new UnprocessableEntityHttpException(
                'Pour pourvoir faire une vente, il faut un chargement de véhicule attribué à votre équipe !'
            );
        }

        $this->carLoadService->increaseProductStockInCarLoad($product, $quantity, $carLoad);
    }

    /**
     * @throws Exception
     */
    public function getOldestNonEmptyStockEntryInWarehouse(Product $product): StockEntry
    {
        $stockEntry = $product->stockEntries()
            ->where('quantity_left', '>', 0)
            ->orderBy('created_at', 'asc')
            ->first();

        if (! $stockEntry) {
            throw new Exception("Aucun stock disponible pour le produit {$product->name}");
        }

        return $stockEntry;
    }

    /**
     * @return array{cartons: int, paquets: int, first_variant_name: string}
     */
    #[ArrayShape(['cartons' => 'integer', 'paquets' => 'integer', 'first_variant_name' => 'string'])]
    public function getFormattedDisplayOfCartonAndPaquets(Product $product, float $quantity): array
    {
        $result = [
            'cartons' => abs(intval($quantity)),
            'paquets' => 0,
            'first_variant_name' => '',
        ];

        /** @var Product|null $firstVariant */
        $firstVariant = Cache::remember(
            "product.{$product->id}.first_variant",
            now()->addHours(36),
            fn () => $product->variants()->first()
        );

        if ($firstVariant) {
            $absoluteQuantity = abs($quantity);
            $decimalPartOfQuantity = $absoluteQuantity - floor($absoluteQuantity);
            $result['paquets'] = (int) number_format(
                ($decimalPartOfQuantity * ($product->base_quantity / $firstVariant->base_quantity)),
                0
            );
            $result['first_variant_name'] = $firstVariant->name;
        }

        return $result;
    }

    public function clearFirstVariantCache(Product $product): void
    {
        Cache::forget("product.{$product->id}.first_variant");
        Cache::forget("product.{$product->id}.formatted_display.*");
    }
}
