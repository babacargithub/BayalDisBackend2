<?php

namespace App\Http\Controllers;

use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Services\CarLoadService;
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

        return Inertia::render('CarLoads/Index', [
            'carLoads' => $carLoads,
            'commercials' => $commercials
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

    public function addItem(Request $request, CarLoad $carLoad)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity_loaded' => 'required|integer|min:1',
            'comment' => 'nullable|string',
        ]);

        try {
            $this->carLoadService->addItem($carLoad, $validated);
            return redirect()->back()
                ->with('success', 'Produit ajouté avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
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
} 