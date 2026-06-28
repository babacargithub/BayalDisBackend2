<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class ApiVehicleController extends Controller
{
    public function index(): JsonResponse
    {
        $vehicles = Vehicle::query()
            ->select('id', 'name', 'plate_number')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $vehicles]);
    }
}
