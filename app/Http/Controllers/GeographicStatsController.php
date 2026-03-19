<?php

namespace App\Http\Controllers;

use App\Services\GeographicStatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GeographicStatsController extends Controller
{
    public function __construct(private readonly GeographicStatsService $geographicStatsService) {}

    public function index(Request $request): Response
    {
        $periodType = in_array($request->query('period_type'), ['all', 'yearly', 'monthly'], strict: true)
            ? $request->query('period_type')
            : 'all';

        $year = max(2000, min(2100, (int) $request->query('year', Carbon::today()->year)));
        $month = max(1, min(12, (int) $request->query('month', Carbon::today()->month)));

        $view = in_array($request->query('view'), ['lignes', 'sectors'], strict: true)
            ? $request->query('view')
            : 'lignes';

        $selectedLigneId = max(0, (int) $request->query('ligne_id', 0));

        [$startDate, $endDate, $periodLabel] = $this->resolvePeriod($periodType, $year, $month);

        // Zones and lignes are always fetched so the selector is always populated.
        $availableZones = DB::table('zones')
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->toArray();

        $availableLignes = DB::table('lignes')
            ->select([
                'lignes.id',
                'lignes.name',
                'lignes.zone_id',
                DB::raw('zones.name as zone_name'),
            ])
            ->leftJoin('zones', 'zones.id', '=', 'lignes.zone_id')
            ->orderBy('zones.name')
            ->orderBy('lignes.name')
            ->get()
            ->toArray();

        $geographicActivity = null;
        $sectorActivity = null;

        if ($view === 'sectors' && $selectedLigneId > 0) {
            $sectorActivity = $this->geographicStatsService->buildSectorActivity(
                ligneId: $selectedLigneId,
                startDate: $startDate,
                endDate: $endDate,
                periodLabel: $periodLabel,
            )->toArray();
        } else {
            $geographicActivity = $this->geographicStatsService->buildGeographicActivity(
                startDate: $startDate,
                endDate: $endDate,
                periodLabel: $periodLabel,
            )->toArray();
        }

        return Inertia::render('Admin/GeographicStats', [
            'periodType' => $periodType,
            'year' => $year,
            'month' => $month,
            'view' => $view,
            'selectedLigneId' => $selectedLigneId,
            'availableZones' => $availableZones,
            'availableLignes' => $availableLignes,
            'geographicActivity' => $geographicActivity,
            'sectorActivity' => $sectorActivity,
        ]);
    }

    /**
     * Resolve Carbon date bounds and a human-readable label from the period type.
     *
     * @return array{Carbon|null, Carbon|null, string|null}
     */
    private function resolvePeriod(string $periodType, int $year, int $month): array
    {
        $monthNames = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        return match ($periodType) {
            'monthly' => [
                Carbon::createFromDate($year, $month, 1)->startOfDay(),
                Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay(),
                $monthNames[$month].' '.$year,
            ],
            'yearly' => [
                Carbon::createFromDate($year, 1, 1)->startOfDay(),
                Carbon::createFromDate($year, 12, 31)->endOfDay(),
                (string) $year,
            ],
            default => [null, null, null],
        };
    }
}
