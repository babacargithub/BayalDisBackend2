<?php

namespace App\Http\Controllers;

use App\Services\RapportService;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function __construct(private readonly RapportService $rapportService) {}

    public function rapport(): Response
    {
        return Inertia::render('Admin/Rapport', [
            'statistics' => $this->rapportService->buildRapportStatistics()->toArray(),
        ]);
    }
}
