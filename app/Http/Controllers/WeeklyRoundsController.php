<?php

namespace App\Http\Controllers;

use App\Services\BeatService;
use Carbon\Carbon;
use Illuminate\View\View;

class WeeklyRoundsController extends Controller
{
    public function __construct(private BeatService $beatService) {}

    public function index(): View
    {
        $rounds = $this->beatService->getWeeklyRoundsSummary();

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        $enrichedRounds = array_map(fn (array $round) => [
            ...$round,
            'date_display' => ucfirst(Carbon::parse($round['planned_at'])->locale('fr')->isoFormat('ddd D MMM')),
            'date_year' => Carbon::parse($round['planned_at'])->format('Y'),
            'cei_badge_class' => self::rateColorBadge($round['cei_rate']),
            'cei_bar_class' => self::rateColorBar($round['cei_rate']),
            'cei_bar_width' => min((int) $round['cei_rate'], 100),
            'strike_badge_class' => self::rateColorBadge($round['strike_rate']),
        ], $rounds);

        $totalRounds = count($rounds);
        $averageCeiRate = $totalRounds > 0
            ? round(array_sum(array_column($rounds, 'cei_rate')) / $totalRounds, 1)
            : 0.0;
        $averageStrikeRate = $totalRounds > 0
            ? round(array_sum(array_column($rounds, 'strike_rate')) / $totalRounds, 1)
            : 0.0;

        $weekSummary = [
            'total_rounds' => $totalRounds,
            'total_debt_to_collect' => array_sum(array_column($rounds, 'total_debt_to_collect')),
            'total_debt_collected' => array_sum(array_column($rounds, 'total_debt_collected')),
            'total_new_invoices' => array_sum(array_column($rounds, 'total_new_invoices')),
            'total_payments' => array_sum(array_column($rounds, 'total_payments')),
            'average_cei_rate' => $averageCeiRate,
            'average_strike_rate' => $averageStrikeRate,
            'avg_cei_top_class' => self::rateColorTop($averageCeiRate),
            'avg_cei_text_class' => self::rateColorText($averageCeiRate),
            'avg_cei_bar_class' => self::rateColorBar($averageCeiRate),
            'avg_cei_bar_width' => min((int) $averageCeiRate, 100),
            'avg_strike_top_class' => self::rateColorTop($averageStrikeRate),
            'avg_strike_text_class' => self::rateColorText($averageStrikeRate),
            'avg_strike_bar_class' => self::rateColorBar($averageStrikeRate),
            'avg_strike_bar_width' => min((int) $averageStrikeRate, 100),
        ];

        return view('ventes.weekly-rounds', [
            'rounds' => $enrichedRounds,
            'weekSummary' => $weekSummary,
            'weekStartLabel' => ucfirst($weekStart->locale('fr')->isoFormat('D MMMM')),
            'weekEndLabel' => ucfirst($weekEnd->locale('fr')->isoFormat('D MMMM YYYY')),
        ]);
    }

    private static function rateColorBadge(float $rate): string
    {
        return $rate >= 75 ? 'bg-emerald-100 text-emerald-800'
            : ($rate >= 50 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
    }

    private static function rateColorBar(float $rate): string
    {
        return $rate >= 75 ? 'bg-emerald-500' : ($rate >= 50 ? 'bg-yellow-500' : 'bg-red-500');
    }

    private static function rateColorText(float $rate): string
    {
        return $rate >= 75 ? 'text-emerald-700' : ($rate >= 50 ? 'text-yellow-700' : 'text-red-700');
    }

    private static function rateColorTop(float $rate): string
    {
        return $rate >= 75 ? 'border-t-emerald-600'
            : ($rate >= 50 ? 'border-t-yellow-500' : 'border-t-red-600');
    }
}
