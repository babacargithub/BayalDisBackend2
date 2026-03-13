<?php

namespace App\Http\Controllers;

use App\Data\CarLoadInventory\CarLoadInventoryResultItemDTO;
use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use App\Models\CarLoadInventoryItem;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Models\Team;
use App\Services\CarLoadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PDF;
use Throwable;

class CarLoadController extends Controller
{
    protected $carLoadService;

    public function __construct(CarLoadService $carLoadService)
    {
        $this->carLoadService = $carLoadService;
    }

    public function index()
    {
        $carLoads = $this->carLoadService->getAllCarLoads();
        $teams = Team::select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('CarLoads/Index', [
            'carLoads' => $carLoads,
            'teams' => $teams,
        ]);
    }

    protected function getMissingInventoryProducts(CarLoad $carLoad)
    {
        // Get products and their total quantity loaded that are not in inventory
        return DB::table('car_load_items as cli')
            ->join('products as p', 'p.id', '=', 'cli.product_id')
            ->select('p.id', 'p.name', DB::raw('SUM(cli.quantity_loaded) as quantity_loaded'))
            ->where('cli.car_load_id', $carLoad->id)
            ->whereNotExists(function ($query) use ($carLoad) {
                $query->select(DB::raw(1))
                    ->from('car_load_inventory_items as clii')
                    ->whereRaw('clii.product_id = cli.product_id')
                    ->where('clii.car_load_inventory_id', $carLoad->inventory->id);
            })
            ->groupBy('p.id', 'p.name')
            ->get();
    }

    public function show(CarLoad $carLoad)
    {
        $carLoad->load([
            'team',
            'items.product',
            'inventory.items.product',
        ]);

        $missingInventoryProducts = $carLoad->inventory
            ? $this->getMissingInventoryProducts($carLoad)
            : collect();

        $products = Product::select('id', 'name', 'parent_id')->orderBy('name')->get();

        return Inertia::render('CarLoads/Show', [
            'carLoad' => $carLoad,
            'products' => $products,
            'missingInventoryProducts' => $missingInventoryProducts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'team_id' => 'required|exists:teams,id',
            'return_date' => 'required|date|after:today',
            'comment' => 'nullable|string',
            'items' => 'array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_loaded' => 'required|integer|min:1',
            'items.*.comment' => 'nullable|string',
        ]);

        // Check for active car loads for the same team
        $hasActiveCarLoad = CarLoad::where('team_id', $validated['team_id'])
            ->where('return_date', '>', now())
            ->where('returned', false)
            ->exists();

        if ($hasActiveCarLoad) {
            return redirect()->back()
                ->withErrors(['team_id' => 'Cette équipe a déjà un chargement actif.'])
                ->withInput();
        }

        $carLoad = $this->carLoadService->createCarLoad($validated);

        return redirect()->route('car-loads.show', $carLoad)
            ->with('success', 'Chargement créé avec succès');
    }

    public function update(Request $request, CarLoad $carLoad)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'team_id' => 'required|exists:teams,id',
            'return_date' => 'required|date|after:today',
            'comment' => 'nullable|string',
        ]);

        // Check for active car loads for the same team (excluding current car load)
        $hasActiveCarLoad = CarLoad::where('team_id', $validated['team_id'])
            ->where('id', '!=', $carLoad->id)
            ->where('return_date', '>', now())
            ->where('returned', false)
            ->exists();

        if ($hasActiveCarLoad) {
            return redirect()->back()
                ->withErrors(['team_id' => 'Cette équipe a déjà un chargement actif.'])
                ->withInput();
        }

        $this->carLoadService->updateCarLoad($carLoad, $validated);

        return redirect()->back()
            ->with('success', 'Chargement mis à jour avec succès');
    }

    public function destroy(CarLoad $carLoad)
    {
        if ($carLoad->returned) {
            return redirect()->back()
                ->with('error', 'Seuls les chargements en cours de chargement peuvent être supprimés');
        }

        $carLoad->delete();

        return redirect()->route('car-loads.index')
            ->with('success', 'Chargement supprimé avec succès');
    }

    /**
     * @throws Throwable
     */
    public function addItems(Request $request, CarLoad $carLoad)
    {

        try {
            $validated = $request->validate([
                'items' => 'array',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity_loaded' => 'required|integer|min:1',
                'items.*.loaded_at' => 'required|date',
                'items.*.comment' => 'nullable|string',
            ]);
            $items = array_map(function ($item) {
                $item['quantity_left'] = $item['quantity_loaded'];

                return $item;
            }, $validated['items']);
            $this->carLoadService->createItemsToCarLoad($carLoad, $items);

            return redirect()->back()
                ->with('success', 'Produit(s) ajouté(s) avec succès');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

    }

    public function updateItem(Request $request, CarLoad $carLoad, CarLoadItem $item)
    {
        $validated = $request->validate([
            'quantity_loaded' => 'required|integer|min:1',
            'quantity_left' => 'nullable|numeric',
            'comment' => 'nullable|string',
            'loaded_at' => 'nullable|date',
        ]);

        try {

            $this->carLoadService->updateItem($item, $validated);

            return redirect()->back()
                ->with('success', 'Produit mis à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    public function deleteItem(CarLoad $carLoad, CarLoadItem $item)
    {
        try {
            $this->carLoadService->deleteItem($item);

            return redirect()->back()
                ->with('success', 'Produit supprimé avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    public function activate(CarLoad $carLoad)
    {
        try {
            $this->carLoadService->activateCarLoad($carLoad);

            return redirect()->back()
                ->with('success', 'Chargement activé avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    public function unload(CarLoad $carLoad)
    {
        try {
            $this->carLoadService->unloadCarLoad($carLoad);

            return redirect()->back()
                ->with('success', 'Chargement déchargé avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * @throws Throwable
     */
    public function createFromPrevious(CarLoad $carLoad)
    {
        try {
            $newCarLoad = $this->carLoadService->createCarLoadFromAnotherPreviousCarLoad($carLoad);

            return redirect()->route('car-loads.show', $newCarLoad)
                ->with('success', 'Nouveau chargement créé avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    public function createInventory(Request $request, CarLoad $carLoad): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'comment' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.total_sold' => 'nullable|int',
            'items.*.total_loaded' => 'nullable|int',
            'items.*.total_returned' => 'nullable|int',
        ]);
        $carLoad->inventory()->create([
            'name' => $validated['name'],
            'user_id' => auth()->id(),
        ]);

        if (isset($validated['items'])) {
            $inventory = $carLoad->inventory;
            $inventory->items()->createMany($validated['items']);
        }

        return redirect()->back()
            ->with('success', 'Inventaire créé avec succès');
    }

    /**
     * @throws Throwable
     */
    public function addInventoryItems(Request $request, CarLoad $carLoad, CarLoadInventory $inventory)
    {
        $validated = $request->validate([
            'items' => 'array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.total_returned' => 'required|numeric',
            'items.*.comment' => 'nullable|string',
        ]);
        // Join through sales_invoices to filter by car_load_id precisely.
        // Date-range filtering was unreliable when car loads overlap in time.
        $salesByProduct = DB::select('
            SELECT
                v.product_id,
                SUM(v.quantity) AS total_quantity_sold
            FROM ventes v
            INNER JOIN sales_invoices si ON si.id = v.sales_invoice_id
            WHERE si.car_load_id = ?
            GROUP BY v.product_id
        ', [$carLoad->id]);
        $salesByProductMap = collect($salesByProduct)->keyBy('product_id');

        $items = collect($validated['items'])
            ->map(function ($item) use ($carLoad, $salesByProductMap) {
                $productId = $item['product_id'];
                $totalQuantitySold = $salesByProductMap->has($productId) ? $salesByProductMap->get($productId)->total_quantity_sold : 0;

                return [
                    'product_id' => $item['product_id'],
                    'product' => Product::where('id', $item['product_id'])->first()->name,
                    'total_returned' => $item['total_returned'],
                    'comment' => $item['comment'] ?? null,
                    'total_loaded' => $carLoad->items()->where('product_id', $item['product_id'])->sum('quantity_loaded'),
                    'total_sold' => $totalQuantitySold,
                ];
            });
        DB::transaction(function () use ($inventory, $items) {

            $inventory->items()->createMany($items);
        });

        return redirect()->back()
            ->with('success', 'Articles ajoutés avec succès');
    }

    public function updateInventoryItem(Request $request, CarLoad $carLoad, CarLoadInventory $inventory, CarLoadInventoryItem $item)
    {
        $validated = $request->validate([
            'total_returned' => 'required|integer|min:0',
            'comment' => 'nullable|string',
        ]);
        // check if entry is closed
        if ($inventory->closed) {
            // throw a validation error
            return redirect()->back()
                ->with('error', 'L\'inventaire est clôturé');
        }

        $item->update($validated);

        return redirect()->back()
            ->with('success', 'Article mis à jour avec succès');
    }

    public function deleteInventoryItem(CarLoad $carLoad, CarLoadInventory $inventory, CarLoadInventoryItem $item)
    {
        $item->delete();

        return redirect()->back()
            ->with('success', 'Article supprimé avec succès');
    }

    public function closeInventory(CarLoad $carLoad, CarLoadInventory $inventory)
    {
        $inventory->update(['closed' => true]);
        $carLoad->returned = true;
        $carLoad->save();

        return redirect()->back()
            ->with('success', 'Inventaire clôturé avec succès');
    }

    public function exportInventoryPdf(CarLoad $carLoad, CarLoadInventory $inventory)
    {
        // Delegate calculations to the service (raw totals only)
        $result = $this->carLoadService->getCalculatedQuantitiesOfProductsInInventory($carLoad, $inventory);
        $items = collect($result['items'])->map(function (CarLoadInventoryResultItemDTO $item) {

            // Result breakdown
            $resultDecimal = $item->resultOfComputation;

            // Price using parent product price
            $resultSign = $resultDecimal < 0 ? '-' : '+';
            $price = $item->priceOfResultComputation;
            $item->resultSign = $resultSign;
            $item->price = $price;

            return $item;
        });

        $totalPrice = $items->sum('price');

        $viewData = [
            'inventory' => $inventory,
            'carLoad' => $carLoad,
            'items' => $items,
            'totalPrice' => $totalPrice,
            'date' => now()->format('d/m/Y H:i'),
        ];

        return view('pdf.inventory', $viewData);
        //        $pdf = PDF::loadView('pdf.inventory', $viewData)->setPaper('a4', 'landscape');
        //        return $pdf->stream("inventaire_{$inventory->id}_{$carLoad->name}.pdf");
        //        return $pdf->stream("inventaire_{$inventory->id}_{$carLoad->name}.pdf");
    }

    public function exportItemsPdf(CarLoad $carLoad)
    {
        $carLoad->load(['items.product', 'commercial']);

        $pdf = PDF::loadView('pdf.car-load-items', [
            'carLoad' => $carLoad,
            'items' => $carLoad->items,
            'date' => now()->format('d/m/Y H:i'),
        ]);

        return $pdf->download("chargement_{$carLoad->id}_{$carLoad->name}.pdf");
    }

    public function createFromInventory(CarLoadInventory $inventory)
    {
        try {
            $this->carLoadService->createFromInventory($inventory);

            return redirect()->route('car-loads.index')
                ->with('success', 'Nouveau chargement créé avec succès à partir de l\'inventaire');
        } catch (\Exception $e) {
            // If we're handling an AJAX/Inertia request
            if (request()->wantsJson()) {
                return response()->json([
                    'error' => $e->getMessage(),
                ], 422);
            }

            // For regular requests, redirect back with error
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function productHistoryInCarLoad(CarLoad $carLoad, Product $product)
    {

        return Inertia::render('CarLoad/ProductHistory', $this->carLoadService->productHistoryInCarLoad($product, $carLoad));

    }
}
