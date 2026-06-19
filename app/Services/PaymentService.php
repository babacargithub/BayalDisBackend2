<?php

namespace App\Services;

use App\Data\Vente\VenteStatsFilter;
use App\Models\BeatStop;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class PaymentService
{
    public function getPaymentsByDate(Carbon $date)
    {
        return Payment::with(['salesInvoice.customer'])
            ->whereDate('created_at', $date)
            ->get()
            ->map(function ($payment) {
                $invoice = $payment->salesInvoice;

                return [
                    'id' => $payment->id,
                    'invoice_id' => $payment->sales_invoice_id,
                    'invoice_created_at' => $invoice->created_at->toDateString(),
                    'customer' => [
                        'name' => $invoice->customer->name,
                        'address' => $invoice->customer->address,
                        'phone_number' => $invoice->customer->phone_number,
                    ],
                    'invoice_date' => $invoice->created_at,
                    'invoice_total' => $invoice->total_amount,
                    'payment_amount' => $payment->amount,
                    'amount_paid' => $invoice->total_payments,
                    'amount_remaining' => $invoice->total_remaining,
                    'payment_method' => $payment->payment_method,
                    'created_at' => $payment->created_at,
                ];
            });
    }

    public function getPaymentStatistics(?Carbon $referenceDate = null): array
    {
        $today = $referenceDate ?? Carbon::today();

        $todayFilter = VenteStatsFilter::new()->inDateInterval($today->copy()->startOfDay(), $today->copy()->endOfDay());
        $weekFilter = VenteStatsFilter::new()->inDateInterval($today->copy()->startOfWeek(), $today->copy()->endOfWeek());
        $monthFilter = VenteStatsFilter::new()->inDateInterval($today->copy()->startOfMonth(), $today->copy()->endOfMonth());

        return [
            'today_total' => $this->sumPayments($todayFilter),
            'today_count' => $this->paymentsQuery($todayFilter)->count(),
            'week_total' => $this->sumPayments($weekFilter),
            'month_total' => $this->sumPayments($monthFilter),
        ];
    }

    /**
     * Return a query scoped to non-cancelled SalesInvoice payments matching the given filter.
     *
     * The returned Builder must be terminated by the caller (->sum('profit'), ->count(), ->get(), …).
     * No records are loaded into memory until the caller executes the query.
     * The Payment model's notCancelled global scope is always active.
     */
    public function paymentsQuery(VenteStatsFilter $filter): Builder
    {
        return $this->buildPaymentQuery($filter);
    }

    /**
     * Sum the amount column of SalesInvoice payments matching the given filter.
     *
     * This is the single entry point for any "how much was collected?" question.
     * All callers — controllers, services, validation rules — must use this method
     * instead of querying the payments table directly.
     */
    public function sumPayments(VenteStatsFilter $filter): int
    {
        return (int) $this->buildPaymentQuery($filter)->sum('amount');
    }

    // -------------------------------------------------------------------------
    // Private query builder
    // -------------------------------------------------------------------------

    /**
     * Build the base payment query shared by paymentsQuery() and sumPayments().
     *
     * Applies, in order:
     *  1. Baseline scope: sales-invoice payments only (whereNotNull sales_invoice_id).
     *  2. Date range from filter->startDate / filter->endDate (exact datetimes, no rounding).
     *  3. Invoice-level scopes (commercial, customer, carLoad, team, beat, tags) via whereHas.
     *
     * The Payment model's notCancelled global scope is always active, so cancelled
     * payments are excluded without any explicit filter here.
     */
    private function buildPaymentQuery(VenteStatsFilter $filter): Builder
    {
        $query = Payment::query()->whereNotNull('sales_invoice_id');

        if ($filter->startDate !== null) {
            $query->where('created_at', '>=', $filter->startDate);
        }

        if ($filter->endDate !== null) {
            $query->where('created_at', '<=', $filter->endDate);
        }

        if ($filter->hasInvoiceLevelFilters()) {
            $query->whereHas('salesInvoice', function (Builder $invoiceQuery) use ($filter) {
                if ($filter->commercialId !== null) {
                    $invoiceQuery->where('commercial_id', $filter->commercialId);
                }

                if ($filter->carLoadId !== null) {
                    $invoiceQuery->where('car_load_id', $filter->carLoadId);
                }

                if ($filter->customerId !== null) {
                    $invoiceQuery->where('customer_id', $filter->customerId);
                }

                if ($filter->customerIds !== null) {
                    $invoiceQuery->whereIn('customer_id', $filter->customerIds);
                }

                if ($filter->teamId !== null) {
                    $invoiceQuery->whereHas(
                        'commercial',
                        fn (Builder $commercialQuery) => $commercialQuery->where('team_id', $filter->teamId)
                    );
                }

                if ($filter->beatId !== null) {
                    $invoiceQuery->whereIn(
                        'customer_id',
                        BeatStop::where('beat_id', $filter->beatId)->select('customer_id')
                    );
                }

                if ($filter->tagIds !== null) {
                    $invoiceQuery->whereHas('customer', function (Builder $customerQuery) use ($filter) {
                        $customerQuery->whereHas(
                            'tags',
                            fn (Builder $tagQuery) => $tagQuery->whereIn('id', $filter->tagIds)
                        );
                    });
                }
            });
        }

        return $query;
    }
}
