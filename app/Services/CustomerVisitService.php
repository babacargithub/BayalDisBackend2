<?php

namespace App\Services;

use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerVisitService
{
    public function getBeats(Commercial $commercial, int $perPage = 20)
    {
        return Beat::where('commercial_id', $commercial->id)
            ->latest()
            ->paginate($perPage)
            ->map(function (Beat $beat) {
                $templateStopsCount = $beat->templateStops()->count();

                return [
                    'id' => $beat->id,
                    'name' => $beat->name,
                    'day_of_week' => $beat->day_of_week?->value,
                    'day_of_week_label' => $beat->day_of_week?->label(),
                    'template_stops_count' => $templateStopsCount,
                    'created_at' => $beat->created_at,
                ];
            });
    }

    public function getTodayStops(Commercial $commercial): array
    {
        $today = now();
        $todayDayOfWeek = DayOfWeek::fromCarbon($today);

        $beatsForToday = Beat::where('commercial_id', $commercial->id)
            ->forDayOfWeek($todayDayOfWeek)
            ->get();

        $allTodayStops = collect();

        foreach ($beatsForToday as $beat) {
            $stops = $beat->getOrGenerateStopsForDate($today);
            $allTodayStops = $allTodayStops->merge($stops);
        }

        return [
            'stops' => $allTodayStops->values(),
            'total' => $allTodayStops->count(),
            'completed' => $allTodayStops->where('status', BeatStop::STATUS_COMPLETED)->count(),
            'pending' => $allTodayStops->where('status', BeatStop::STATUS_PLANNED)->count(),
            'cancelled' => $allTodayStops->where('status', BeatStop::STATUS_CANCELLED)->count(),
        ];
    }

    public function getBeatDetails(Beat $beat): Beat
    {
        return $beat->load(['stops' => function ($query) {
            $query->with(['customer:id,name,phone_number,address'])
                ->orderBy('visit_planned_at');
        }]);
    }

    public function completeBeatStop(BeatStop $beatStop, array $data): BeatStop
    {
        DB::transaction(function () use ($beatStop, $data) {
            $beatStop->update([
                'status' => BeatStop::STATUS_COMPLETED,
                'visited_at' => now(),
                'notes' => $data['notes'] ?? null,
                'gps_coordinates' => $data['gps_coordinates'],
                'resulted_in_sale' => $data['resulted_in_sale'] ?? false,
            ]);
        });

        return $beatStop->load('customer');
    }

    public function cancelBeatStop(BeatStop $beatStop, array $data): BeatStop
    {
        DB::transaction(function () use ($beatStop, $data) {
            $beatStop->update([
                'status' => BeatStop::STATUS_CANCELLED,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return $beatStop->load('customer');
    }

    public function updateBeatStop(BeatStop $beatStop, array $data): BeatStop
    {
        DB::transaction(function () use ($beatStop, $data) {
            $beatStop->update([
                'notes' => $data['notes'] ?? $beatStop->notes,
                'visit_planned_at' => $data['visit_planned_at'] ?? $beatStop->visit_planned_at,
            ]);
        });

        return $beatStop->load('customer');
    }

    public function canAccessBeatStop(Commercial $commercial, BeatStop $beatStop): bool
    {
        return true;
    }

    public function canAccessBeat(Commercial $commercial, Beat $beat): bool
    {
        return true;
    }

    public function terminateBeatStopIfCustomerHasPlannedOne(Customer $customer): void
    {
        $beatStop = $customer->beatStops()
            ->whereDate('visit_date', now()->toDateString())
            ->where('status', BeatStop::STATUS_PLANNED)
            ->first();

        if ($beatStop) {
            $beatStop->status = BeatStop::STATUS_COMPLETED;
            $beatStop->visited_at = now();
            $beatStop->notes = 'Arrêt marqué comme terminé suite à une vente';
            $beatStop->gps_coordinates = $customer->gps_coordinates;
            $beatStop->save();
        }
    }
}
