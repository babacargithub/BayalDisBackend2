<?php

namespace App\Services;

use App\Models\CustomerVisit;
use App\Models\VisitBatch;
use App\Models\Commercial;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerVisitService
{
    /**
     * Get visit batches for a commercial
     */
    public function getVisitBatches(Commercial $commercial, int $perPage = 20)
    {
        $batches = VisitBatch::latest()
        ->paginate($perPage)->map(function (VisitBatch $batch){
                /**
                 * visitsCount: json['visits_count'],
                 * completedCount: json['completed_count'],
                 * pendingCount: json['pending_count'],
                 */
            return [
                'id' => $batch->id,
                'name' => $batch->name,
                'visit_date' => $batch->visit_date,
                'visits'=>[],
                'visits_count' => $batch->visits->count(),
                'pending_count' => $batch->visits->where('status', 'planned')->count(),
                'completed_count' => $batch->visits->where('status', 'completed')->count(),
                'created_at' => $batch->created_at,
            ];
            });
        return $batches;
    }

    /**
     * Get today's visits for a commercial
     */
    public function getTodayVisits(Commercial $commercial): array
    {
        $visits = VisitBatch::whereDate('visit_date', today())
            ->with(['visits' => function ($query) {
                $query->select('id', 'visit_batch_id', 'customer_id', 'status', 'visit_planned_at', 'visit_completed_at', 'notes', 'gps_coordinates')
                    ->with(['customer:id,name,phone_number,address']);
            }])
            ->get()
            ->pluck('visits')
            ->flatten();

        return [
            'visits' => $visits,
            'total' => $visits->count(),
            'completed' => $visits->where('status', 'completed')->count(),
            'pending' => $visits->where('status', 'planned')->count(),
            'cancelled' => $visits->where('status', 'cancelled')->count(),
        ];
    }

    /**
     * Get details of a specific visit batch
     */
    public function getVisitBatchDetails(VisitBatch $batch): VisitBatch
    {
        return $batch->load(['visits' => function ($query) {
            $query->with(['customer:id,name,phone_number,address'])
                ->orderBy('visit_planned_at');
        }]);
    }

    /**
     * Complete a visit
     */
    public function completeVisit(CustomerVisit $visit, array $data): CustomerVisit
    {
        DB::transaction(function () use ($visit, $data) {
            $visit->update([
                'status' => 'completed',
                'visit_completed_at' => now(),
                'notes' => $data['notes'] ?? null,
                'gps_coordinates' => $data['gps_coordinates'],
                'resulted_in_sale' => $data['resulted_in_sale'],
            ]);
        });

        return $visit->load('customer');
    }

    /**
     * Cancel a visit
     */
    public function cancelVisit(CustomerVisit $visit, array $data): CustomerVisit
    {
        DB::transaction(function () use ($visit, $data) {
            $visit->update([
                'status' => 'cancelled',
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return $visit->load('customer');
    }

    /**
     * Update a visit's details
     */
    public function updateVisit(CustomerVisit $visit, array $data): CustomerVisit
    {
        DB::transaction(function () use ($visit, $data) {
            $visit->update([
                'notes' => $data['notes'] ?? $visit->notes,
                'visit_planned_at' => $data['visit_planned_at'] ?? $visit->visit_planned_at,
            ]);
        });

        return $visit->load('customer');
    }

    /**
     * Check if a commercial can access a visit
     */
    public function canAccessVisit(Commercial $commercial, CustomerVisit $visit): bool
    {
        return true;
    }

    /**
     * Check if a commercial can access a visit batch
     */
    public function canAccessVisitBatch(Commercial $commercial, VisitBatch $batch): bool
    {
        return true;
    }
} 