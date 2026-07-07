<?php

namespace App\Http\Controllers;

use App\Models\Beat;
use App\Models\BeatRound;
use App\Services\BeatService;
use Illuminate\View\View;

class BeatRoundPerformanceController extends Controller
{
    public function __construct(private BeatService $beatService) {}

    public function show(Beat $beat, BeatRound $beatRound): View
    {
        $performance = $this->beatService->calculateRoundPerformance($beatRound);

        $beatRound->load(['commercial:id,name', 'vehicle:id,name,plate_number']);

        return view('beats.round-performance', [
            'beat' => $beat,
            'round' => $beatRound,
            'performance' => $performance,
        ]);
    }
}
