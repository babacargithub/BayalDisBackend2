<?php

namespace App\Http\Controllers;

use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Models\CarLoadInventory;
use App\Models\CarLoadInventoryItem;
use App\Services\CarLoadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
        $commercials = \App\Models\Commercial::select('id', 'name')
            ->orderBy('name')
            ->get();
        $products = Product::select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('CarLoads/Index', [
            'carLoads' => $carLoads,
            'commercials' => $commercials,
            'products' => $products
        ]);
    }

    public function show(CarLoad $carLoad)
    {
        $carLoad->load(['items.product', 'commercial']);
        $products = Product::all();

        return Inertia::render('CarLoads/Show', [
            'carLoad' => $carLoad,
            'products' => $products
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'commercial_id' => 'required|exists:users,id',
            'comment' => 'nullable|string',
            'items' => 'array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_loaded' => 'required|integer|min:1',
            'items.*.comment' => 'nullable|string',
        ]);

        $carLoad = $this->carLoadService->createCarLoad($validated);

        return redirect()->route('car-loads.show', $carLoad)
            ->with('success', 'Chargement créé avec succès');
    }

    public function update(Request $request, CarLoad $carLoad)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'commercial_id' => 'required|exists:users,id',
            'comment' => 'nullable|string',
        ]);

        $this->carLoadService->updateCarLoad($carLoad, $validated);

        return redirect()->back()
            ->with('success', 'Chargement mis à jour avec succès');
    }

    public function destroy(CarLoad $carLoad)
    {
        if ($carLoad->status !== 'LOADING') {
            return redirect()->back()
                ->with('error', 'Seuls les chargements en cours de chargement peuvent être supprimés');
        }

        $carLoad->delete();

        return redirect()->route('car-loads.index')
            ->with('success', 'Chargement supprimé avec succès');
    }

    public function addItems(Request $request, CarLoad $carLoad)
    {
        $validated = $request->validate([
            'items' => 'array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_loaded' => 'required|integer|min:1',
            'items.*.comment' => 'nullable|string',
        ]);


           $carLoad->items()->createMany($validated['items']);
            return redirect()->back()
                ->with('success', 'Produit ajouté avec succès');

    }

    public function updateItem(Request $request, CarLoad $carLoad, CarLoadItem $item)
    {
        $validated = $request->validate([
            'quantity_loaded' => 'required|integer|min:1',
            'comment' => 'nullable|string',
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

    public function createFromPrevious(CarLoad $carLoad)
    {
        try {
            $newCarLoad = $this->carLoadService->createFromPrevious($carLoad);
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
            "comment" => "nullable|string",
            "items" => "nullable|array",
            "items.*.product_id" => "nullable|exists:products,id",
            "items.*.total_sold" => "nullable|int",
            "items.*.total_loaded" => "nullable|int",
            "items.*.total_returned" => "nullable|int",
        ]);
        $carLoad->inventory()->create([
            'name' => $validated['name'],
            'user_id' => auth()->id(),
            'comment' => $validated['comment'] ?? null  ,
        ]);

        $inventory = $carLoad->inventory;
        $inventory->items()->createMany($validated["items"]);



        return redirect()->back()
            ->with('success', 'Inventaire créé avec succès');
    }

    public function addInventoryItems(Request $request, CarLoad $carLoad, CarLoadInventory $inventory)
    {
        $validated = $request->validate([
            'items' => 'array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_counted' => 'required|integer|min:0',
            'items.*.comment' => 'nullable|string',
        ]);

        $inventory->items()->createMany($validated['items']);

        return redirect()->back()
            ->with('success', 'Articles ajoutés avec succès');
    }

    public function updateInventoryItem(Request $request, CarLoad $carLoad, CarLoadInventory $inventory, CarLoadInventoryItem $item)
    {
        $validated = $request->validate([
            'quantity_counted' => 'required|integer|min:0',
            'comment' => 'nullable|string',
        ]);

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

        return redirect()->back()
            ->with('success', 'Inventaire clôturé avec succès');
    }
} 