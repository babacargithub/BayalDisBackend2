<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CarLoadService;
use Illuminate\Http\JsonResponse;

class ApiCarLoadController extends Controller
{
    protected $carLoadService;

    public function __construct(CarLoadService $carLoadService)
    {
        $this->carLoadService = $carLoadService;
    }

    public function getCurrentItems(): JsonResponse
    {
        $items = $this->carLoadService->getCurrentCarLoadItems();

        return response()->json($items);
    }
} 