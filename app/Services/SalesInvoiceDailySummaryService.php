<?php

namespace App\Services;

use App\Data\SalesInvoice\SalesInvoiceDailySummaryDTO;
use App\Data\SalesInvoice\SalesInvoicesDailyTotalsDTO;
use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the daily sales invoice summary used by the Ventes/Index view.
 *
 * Reads exclusively from stored columns on sales_invoices — no joins or
 * recomputed aggregates. This keeps the query fast even at large invoice counts.
 */
class SalesInvoiceDailySummaryService
{
    /**
     * Fetch all invoices for the given date and return them as DTOs.
     *
     * Filters applied in the database:
     *   – date  (required, defaults to today in the controller)
     *   – commercial_id  (optional)
     *   – paid_status    (optional: 'paid' | 'partial' | 'unpaid')
     *
     * @return Collection<int, SalesInvoiceDailySummaryDTO>
     */
    public function getDailySummaries(
        Carbon $date,
        ?int $commercialId,
        ?string $paidStatus,
    ): Collection {
        $query = SalesInvoice::query()
            ->with([
                'customer:id,name,address',
                'commercial:id,name',
            ])
            ->whereDate('created_at', $date);

        if ($commercialId !== null) {
            $query->where('commercial_id', $commercialId);
        }

        if ($paidStatus !== null) {
            $query->where('status', $this->resolveStatusFilter($paidStatus));
        }

        return $query
            ->latest()
            ->get()
            ->map(fn (SalesInvoice $invoice) => SalesInvoiceDailySummaryDTO::fromInvoice($invoice));
    }

    /**
     * Aggregate individual summary DTOs into a single totals DTO.
     *
     * @param  Collection<int, SalesInvoiceDailySummaryDTO>  $summaries
     */
    public function computeDailyTotals(Collection $summaries): SalesInvoicesDailyTotalsDTO
    {
        return SalesInvoicesDailyTotalsDTO::fromSummaries($summaries);
    }

    /**
     * Map the user-facing paid_status string to the corresponding SalesInvoiceStatus value.
     */
    private function resolveStatusFilter(string $paidStatus): string
    {
        return match ($paidStatus) {
            'paid' => SalesInvoiceStatus::FullyPaid->value,
            'partial' => SalesInvoiceStatus::PartiallyPaid->value,
            'unpaid' => SalesInvoiceStatus::Draft->value,
            default => SalesInvoiceStatus::Draft->value,
        };
    }
}
