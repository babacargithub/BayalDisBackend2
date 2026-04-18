<?php

namespace App\Services;

use App\Data\SalesInvoice\DailySalesInvoiceItemDTO;
use App\Data\SalesInvoice\SalesInvoicesDailyTotalsDTO;
use App\Enums\SalesInvoiceStatus;
use App\Models\BeatStop;
use App\Models\Payment;
use App\Models\SalesInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the daily sales timeline used by the Ventes/Index view.
 *
 * The timeline merges two types of items:
 *   – Sales invoices created on the requested date.
 *   – Payments collected on the requested date that belong to invoices from a previous day.
 *
 * All data is read from stored columns on sales_invoices and payments — no recomputed
 * aggregates at query time — keeping the response fast even at large record counts.
 */
class DailySalesInvoicesService
{
    /**
     * Return the full merged timeline for the given date, sorted newest-first.
     *
     * The timeline includes:
     *   – Invoice rows: invoices created on `$date`, optionally filtered by commercial and status.
     *   – Payment rows: payments collected on `$date` whose invoice was created on a different day.
     *
     * @return Collection<int, DailySalesInvoiceItemDTO>
     */
    public function getDailyTimeline(
        Carbon $date,
        ?int $commercialId,
        ?string $paidStatus,
        ?int $beatId = null,
    ): Collection {
        $beatCustomerIds = $this->resolveBeatCustomerIds($beatId);

        $invoiceItems = $this->getDailySales($date, $commercialId, $paidStatus, $beatCustomerIds);

        $pastInvoicePaymentQuery = Payment::with(['salesInvoice.customer'])
            ->whereDate('created_at', $date)
            ->whereHas('salesInvoice', fn ($query) => $query->whereDate('created_at', '<>', $date));

        if ($beatCustomerIds !== null) {
            $pastInvoicePaymentQuery->whereHas(
                'salesInvoice',
                fn ($query) => $query->whereIn('customer_id', $beatCustomerIds)
            );
        }

        $pastInvoicePaymentItems = $pastInvoicePaymentQuery
            ->get()
            ->map(fn (Payment $payment) => DailySalesInvoiceItemDTO::fromPayment($payment));

        return $invoiceItems
            ->concat($pastInvoicePaymentItems)
            ->sortByDesc('sortKey')
            ->values();
    }

    /**
     * Return the customer IDs belonging to the given beat's template stops,
     * or null when no beat filter is active.
     *
     * @return int[]|null
     */
    private function resolveBeatCustomerIds(?int $beatId): ?array
    {
        if ($beatId === null) {
            return null;
        }

        return BeatStop::where('beat_id', $beatId)
            ->whereNull('visit_date')
            ->pluck('customer_id')
            ->all();
    }

    /**
     * Fetch invoices for the given date and return them as DTOs.
     *
     * Filters applied in the database:
     *   – date           (required, defaults to today in the controller)
     *   – commercial_id  (optional)
     *   – paid_status    (optional: 'paid' | 'partial' | 'unpaid')
     *
     * @return Collection<int, DailySalesInvoiceItemDTO>
     */
    /**
     * @param  int[]|null  $beatCustomerIds  Pre-resolved customer IDs for a beat filter, or null for no beat filter.
     */
    public function getDailySales(
        Carbon $date,
        ?int $commercialId,
        ?string $paidStatus,
        ?array $beatCustomerIds = null,
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

        if ($beatCustomerIds !== null) {
            $query->whereIn('customer_id', $beatCustomerIds);
        }

        return $query
            ->latest()
            ->get()
            ->map(fn (SalesInvoice $invoice) => DailySalesInvoiceItemDTO::fromInvoice($invoice));
    }

    /**
     * Aggregate timeline DTOs into a single totals DTO.
     *
     * Only invoice rows contribute to the totals; payment rows are ignored.
     *
     * @param  Collection<int, DailySalesInvoiceItemDTO>  $timelineItems
     */
    public function computeDailyTotals(Collection $timelineItems): SalesInvoicesDailyTotalsDTO
    {
        return SalesInvoicesDailyTotalsDTO::fromSummaries($timelineItems);
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
