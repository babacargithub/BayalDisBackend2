<?php

namespace App\Services\Concerns;

use App\Data\Vente\VenteStatsFilter;
use App\Models\BeatStop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared filter logic for applying a VenteStatsFilter to a SalesInvoice query builder.
 *
 * Provides two levels of scoping:
 *  - Entity scope (no date range): used for real-time snapshots such as outstanding balance
 *    or beginning/ending AR where the creation date must not be restricted.
 *  - Full scope (entity + date range): used for period-based queries such as invoices
 *    created during a reporting window.
 *
 * This trait is the single source of truth for VenteStatsFilter → SalesInvoice query translation.
 * Any service that needs to scope a SalesInvoice query by commercial, customer, team, beat,
 * car load, or tags should use this trait rather than duplicating the filter logic.
 */
trait AppliesInvoiceScopeFromFilter
{
    /**
     * Apply all entity-level constraints from the filter to the given SalesInvoice query,
     * excluding the date range.
     *
     * Use this for queries that must reflect the current state of invoices regardless of when
     * they were created — e.g. outstanding balance, beginning AR before a period.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyEntityScopeToInvoiceQuery(Builder $query, VenteStatsFilter $filter): Builder
    {
        if ($filter->commercialId !== null) {
            $query->where('commercial_id', $filter->commercialId);
        }

        if ($filter->carLoadId !== null) {
            $query->where('car_load_id', $filter->carLoadId);
        }

        if ($filter->customerId !== null) {
            $query->where('customer_id', $filter->customerId);
        }

        if ($filter->customerIds !== null) {
            $query->whereIn('customer_id', $filter->customerIds);
        }

        if ($filter->teamId !== null) {
            $query->whereHas(
                'commercial',
                fn (Builder $commercialQuery) => $commercialQuery->where('team_id', $filter->teamId),
            );
        }

        if ($filter->beatId !== null) {
            $query->whereIn(
                'customer_id',
                BeatStop::where('beat_id', $filter->beatId)->select('customer_id'),
            );
        }

        if ($filter->tagIds !== null) {
            $query->whereHas('customer', function (Builder $customerQuery) use ($filter) {
                $customerQuery->whereHas(
                    'tags',
                    fn (Builder $tagQuery) => $tagQuery->whereIn('id', $filter->tagIds),
                );
            });
        }

        return $query;
    }

    /**
     * Apply the full VenteStatsFilter (entity scope + date range) to the given SalesInvoice query.
     *
     * Use this for period-scoped queries — invoices created within a specific date window.
     * The date range is taken from filter->startDate and filter->endDate.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyFullScopeToInvoiceQuery(Builder $query, VenteStatsFilter $filter): Builder
    {
        $this->applyEntityScopeToInvoiceQuery($query, $filter);

        if ($filter->startDate !== null) {
            $query->where('created_at', '>=', $filter->startDate);
        }

        if ($filter->endDate !== null) {
            $query->where('created_at', '<=', $filter->endDate);
        }

        return $query;
    }
}
