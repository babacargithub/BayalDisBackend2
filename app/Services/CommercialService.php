<?php

namespace App\Services;

use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\CommercialPenalty;
use App\Models\CommercialWorkPeriod;
use App\Models\SalesInvoice;
use App\Services\Commission\DailyCommissionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Glue service for HR / payroll reconciliation.
 *
 * Delegates all inventory calculations to CarLoadService and all invoice
 * queries to SalesInvoice. No new calculation logic lives here.
 */
class CommercialService
{
    public function __construct(
        private readonly CarLoadService $carLoadService,
        private readonly SalesInvoiceService $salesInvoiceService,
        private readonly DailyCommissionService $dailyCommissionService,
    ) {}

    /**
     * Returns summarised inventory results for the given commercial's team car loads
     * whose dates overlap the specified range. Only closed inventories are included.
     *
     * Delegates computation to CarLoadService::getCalculatedQuantitiesOfProductsInInventory
     * and sums the monetary result across all products.
     *
     * @return array<int, array{
     *     inventory_id: int,
     *     inventory_name: string,
     *     car_load_name: string,
     *     load_date: string|null,
     *     result_amount: int,
     *     is_deficit: bool,
     *     is_surplus: bool,
     * }>
     */
    public function getInventoryResultsForCommercial(
        Commercial $commercial,
        string $startDate,
        string $endDate,
    ): array {
        $team = $commercial->team;
        if ($team === null) {
            return [];
        }

        $carLoadsWithClosedInventory = CarLoad::where('team_id', $team->id)
            ->where(function ($query) use ($startDate, $endDate): void {
                // A closed car load belongs to the period whose range contains its return_date.
                // Active car loads (no return_date yet) are shown in any period they started in.
                $query->whereBetween('return_date', [$startDate, $endDate])
                    ->orWhere(function ($activeQuery) use ($endDate): void {
                        $activeQuery->whereNull('return_date')
                            ->where('load_date', '<=', $endDate);
                    });
            })
            ->with('inventory.items.product', 'inventory.penalties')
            ->get()
            ->filter(fn (CarLoad $carLoad) => $carLoad->inventory?->closed === true);

        $results = [];

        foreach ($carLoadsWithClosedInventory as $carLoad) {
            $inventory = $carLoad->inventory;
            $calculatedItems = $this->carLoadService->getCalculatedQuantitiesOfProductsInInventory($carLoad, $inventory);

            $totalResultAmount = collect($calculatedItems['items'])->sum('priceOfResultComputation');

            $linkedPenalties = $inventory->penalties->map(fn (CommercialPenalty $penalty) => [
                'id' => $penalty->id,
                'amount' => $penalty->amount,
                'work_day' => $penalty->work_day->toDateString(),
                'reason' => $penalty->reason,
            ])->values()->all();

            $results[] = [
                'inventory_id' => $inventory->id,
                'inventory_name' => $inventory->name,
                'car_load_name' => $carLoad->name,
                'load_date' => $carLoad->load_date?->toDateString(),
                'result_amount' => $totalResultAmount,
                'is_deficit' => $totalResultAmount < 0,
                'is_surplus' => $totalResultAmount > 0,
                'linked_penalties' => $linkedPenalties,
            ];
        }

        return $results;
    }

    /**
     * Create one penalty per selected overdue invoice in a single transaction.
     *
     * The reason is auto-generated from the invoice due date and customer name.
     * Each penalty is linked to its source invoice via sales_invoice_id.
     * Commission is recalculated once for the work day after all penalties are persisted.
     *
     * @param  int[]  $salesInvoiceIds
     * @return CommercialPenalty[]
     *
     * @throws \Throwable
     */
    public function createPenaltiesFromOverdueInvoices(
        Commercial $commercial,
        array $salesInvoiceIds,
        string $workDay,
        int $createdByUserId,
    ): array {
        return DB::transaction(function () use ($commercial, $salesInvoiceIds, $workDay, $createdByUserId): array {
            $workPeriod = CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
                commercialId: $commercial->id,
                date: $workDay,
            );

            $invoices = SalesInvoice::with('customer:id,name')
                ->whereIn('id', $salesInvoiceIds)
                ->get();

            $createdPenalties = [];

            foreach ($invoices as $invoice) {
                $formattedDueDate = $invoice->should_be_paid_at?->format('d/m/Y') ?? '—';
                $autoReason = "Facture impayée du {$formattedDueDate} pour client {$invoice->customer->name}";

                $createdPenalties[] = $workPeriod->penalties()->create([
                    'amount' => $invoice->total_remaining,
                    'reason' => $autoReason,
                    'work_day' => $workDay,
                    'created_by_user_id' => $createdByUserId,
                    'sales_invoice_id' => $invoice->id,
                ]);
            }

            $this->dailyCommissionService->recalculateDailyCommissionForWorkDay(
                $commercial,
                $workPeriod,
                $workDay,
            );

            return $createdPenalties;
        });
    }

    /**
     * Returns overdue unpaid invoices created for the given commercial within the date range.
     * Delegates to SalesInvoiceService which uses the SalesInvoice::scopeOverdue() scope.
     */
    public function getOverdueInvoicesForCommercial(
        Commercial $commercial,
        string $startDate,
        string $endDate,
    ): EloquentCollection {
        return $this->salesInvoiceService->getOverdueInvoicesForCommercial($commercial, $startDate, $endDate);
    }

    /**
     * Create a penalty for the given commercial on the specified work day.
     *
     * Finds or creates the weekly CommercialWorkPeriod covering work_day, then
     * records the penalty and triggers a daily commission recalculation so the
     * commercial's net pay stays consistent with the new deduction.
     *
     * Mirrors CommissionController::storePenalty — this is the single place that
     * centralises penalty creation triggered from the HR reconciliation page.
     *
     * @throws \Throwable
     */
    public function createPenaltyForCommercial(
        Commercial $commercial,
        int $amount,
        string $reason,
        string $workDay,
        int $createdByUserId,
        ?int $carLoadInventoryId = null,
        ?int $salesInvoiceId = null,
    ): CommercialPenalty {
        return DB::transaction(function () use ($commercial, $amount, $reason, $workDay, $createdByUserId, $carLoadInventoryId, $salesInvoiceId): CommercialPenalty {
            $workPeriod = CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
                commercialId: $commercial->id,
                date: $workDay,
            );

            $penalty = $workPeriod->penalties()->create([
                'amount' => $amount,
                'reason' => $reason,
                'work_day' => $workDay,
                'created_by_user_id' => $createdByUserId,
                'car_load_inventory_id' => $carLoadInventoryId,
                'sales_invoice_id' => $salesInvoiceId,
            ]);

            $this->dailyCommissionService->recalculateDailyCommissionForWorkDay(
                $commercial,
                $workPeriod,
                $workDay,
            );

            return $penalty;
        });
    }

    /**
     * Returns penalties recorded for the given commercial whose work_day falls within the date range.
     */
    public function getPenaltiesForCommercial(
        Commercial $commercial,
        string $startDate,
        string $endDate,
    ): Collection {
        return CommercialPenalty::query()
            ->whereHas('workPeriod', fn ($query) => $query->where('commercial_id', $commercial->id))
            ->whereBetween('work_day', [$startDate, $endDate])
            ->with('workPeriod:id,period_start_date,period_end_date')
            ->orderBy('work_day')
            ->get();
    }
}
