<?php

namespace App\Services;

use App\Models\CarLoad;
use App\Models\CarLoadItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

            return $item;
        });
    }

    public function deleteItem(CarLoadItem $item): void
    {
        if ($item->carLoad->status === 'UNLOADED') {
            throw new \Exception('Cannot delete items from an unloaded car load');
        }

        $item->delete();
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
            ->paginate(10);
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
            ->paginate(100);
    }

    public function getCurrentCarLoadItems()
    {
        // TO
       /* $currentCarLoad = CarLoad::where('commercial_id', auth()->user()->commercial_id)
            // check that the car laod is not past
            ->where('return_date', '>', now())
            ->with(['items.product'])
            ->first(); */

        $currentCarLoad = CarLoad::
            with(['items.product'])
            ->find(2)
            ->first();

        if (!$currentCarLoad) {
            return [];
        }

        return $currentCarLoad->items->map(function ($item) {
            return [
                'product_name' => $item->product->name,
                'quantity_loaded' => $item->quantity_loaded,
                'created_at' => $item->created_at->format('Y-m-d H:i:s')
            ];
        });
    }
} 