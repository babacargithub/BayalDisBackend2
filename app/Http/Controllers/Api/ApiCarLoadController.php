<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CarLoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiCarLoadController extends Controller
{
    protected $carLoadService;

    public function __construct(CarLoadService $carLoadService)
    {
        $this->carLoadService = $carLoadService;
    }

    public function getCurrentItems(Request $request): JsonResponse
    {
        $team = $request->user()->commercial->team;
        $items = $this->carLoadService->getCurrentCarLoadItems($team);

        return response()->json($items);
    }

    public function getProductVariants(Product $product)
    {
        $variants = $product->variants()->get();

        return response()->json($variants->map(function ($variant) {
            return [
                'id' => $variant->id,
                'name' => $variant->name,
                'stock_available' => $variant->stock_available,
                'base_quantity' => $variant->base_quantity,
            ];
        }));
    }

    /**
     * @throws \Throwable
     */
    public function transformToVariants(Request $request, Product $product)
    {
        $validated = $request->validate([
            'quantityOfBaseProductToTransform' => 'required|integer|min:1',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unused_quantity' => 'required|integer|min:0',
        ]);

        try {
            $team = $request->user()->commercial->team;
            $currentCarLoad = $this->carLoadService->getCurrentCarLoadForTeam($team);

            if (! $currentCarLoad) {
                return response()->json(['message' => "Votre équipe/véhicule ne dispose pas d'un chargement en cours dans le système."], 422);
            }

            $this->carLoadService->transformToVariants($product, $validated, $currentCarLoad);

            return response()->json(['message' => 'Transformation effectuée avec succès']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], status: 422);
        }
    }
}
