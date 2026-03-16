<?php

namespace App\Services;

use App\Data\ActivityReport\CommercialActivityReportDTO;
use App\Data\Dashboard\DashboardStats;
use App\Data\Vente\PaidStatus;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\SalesInvoiceStatus;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Depense;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\StockEntry;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for all SalesInvoice calculations and aggregate statistics.
 *
 * This service is read-only — it never mutates data. For CRUD operations,
 * use SalesInvoiceService instead.
 *
 * Also used by SalesInvoice::recalculateStoredTotals() to refresh per-invoice
 * stored financial columns (total_amount, total_payments, etc.).
 */
class SalesInvoiceStatsService
{
    // =========================================================================
    // Per-invoice calculation helpers (used by SalesInvoice::recalculateStoredTotals)
    // =========================================================================

    /**
     * Compute the total sales amount (price × quantity) across all INVOICE_ITEM ventes
     * belonging to the given invoice.
     *
     * Used by SalesInvoice::recalculateStoredTotals() to refresh the stored total_amount column.
     */
    public function calculateTotalAmountForInvoice(SalesInvoice $invoice): int
    {
        return (int) $this->buildBaseInvoiceItemsQuery($invoice)
            ->selectRaw('SUM(price * quantity) as total')
            ->value('total');
    }

    /**
     * Compute the total estimated profit across all INVOICE_ITEM ventes belonging to the given invoice.
     *
     * "Estimated" because it reflects the full potential profit regardless of payment status.
     * Used by SalesInvoice::recalculateStoredTotals() to refresh the stored total_estimated_profit column.
     */
    public function calculateTotalEstimatedProfitForInvoice(SalesInvoice $invoice): int
    {
        return (int) $this->buildBaseInvoiceItemsQuery($invoice)
            ->sum('profit');
    }

    /**
     * Compute the total amount received across all payments for the given invoice.
     *
     * Used by SalesInvoice::recalculateStoredTotals() to refresh the stored total_payments column.
     */
    public function calculateTotalPaymentsForInvoice(SalesInvoice $invoice): int
    {
        return (int) $this->buildBaseInvoicePaymentsQuery($invoice)
            ->sum('amount');
    }

    /**
     * Compute the total realized profit across all payments for the given invoice.
     *
     * "Realized" profit is proportional to the amount paid and is stored per-payment at creation
     * time via the Payment model's creating event.
     * Used by SalesInvoice::recalculateStoredTotals() to refresh the stored total_realized_profit column.
     */
    public function calculateTotalRealizedProfitForInvoice(SalesInvoice $invoice): int
    {
        return (int) $this->buildBaseInvoicePaymentsQuery($invoice)
            ->sum('profit');
    }

    // =========================================================================
    // Per-vente profit calculation
    // =========================================================================

    /**
     * Calculate the profit for a single Vente using historical cost price from StockEntry records.
     *
     * The historical cost price is the weighted average of all stock entries for the product
     * that existed at or before the time of the sale. This ensures profit is calculated against
     * the actual cost paid at purchase time, not the product's current cost_price.
     *
     * Future: additional deductions (car load expenses, gas costs, etc.) will be applied here.
     */
    public function calculateProfitForVente(Vente $vente): int
    {
        if (! $vente->product) {
            return 0;
        }

        $historicalCostPrice = $this->determineHistoricalCostPriceForVente($vente);

        return (int) round(($vente->price - $historicalCostPrice) * $vente->quantity);
    }

    /**
     * Determine the full historical unit cost for a vente using the weighted average
     * of all stock entries for the product at or before the vente's creation date.
     *
     * The total unit cost includes:
     *   - unit_price:          actual purchase cost per unit (FIFO batch price)
     *   - transportation_cost: freight cost allocated to this batch at stock entry time
     *   - packaging_cost:      packaging material cost per unit at stock entry time
     *
     * Falls back to the product's current cost_price (purchase only) if no stock entries exist.
     */
    private function determineHistoricalCostPriceForVente(Vente $vente): float
    {
        $venteDate = $vente->created_at ?? now();

        $stockEntries = StockEntry::where('product_id', $vente->product_id)
            ->where('created_at', '<=', $venteDate)
            ->get();

        if ($stockEntries->isEmpty()) {
            return (float) $vente->product->cost_price;
        }

        $totalQuantity = $stockEntries->sum('quantity');

        $totalValue = $stockEntries->sum(
            fn (StockEntry $entry) => $entry->quantity * (
                $entry->unit_price
                + $entry->transportation_cost
                + $entry->packaging_cost
            )
        );

        return $totalQuantity > 0
            ? $totalValue / $totalQuantity
            : (float) $vente->product->cost_price;
    }

    // =========================================================================
    // Aggregate statistics across multiple invoices
    // =========================================================================

    /**
     * Compute the total sales amount (price * quantity) for ventes
     * within the given date range and filter constraints.
     *
     * This is the single source of truth for total sales computation in the application.
     * All dashboard, report, and API endpoints must use this method — never query ventes directly.
     *
     * @param  Carbon|null  $startDate  Inclusive start date; null means no lower bound (all-time).
     * @param  Carbon|null  $endDate  Inclusive end date; null means no upper bound (all-time).
     * @param  VenteStatsFilter  $filter  Additional constraints (paid status, commercial, car load, customer, type).
     * @return int Total sales amount in XOF.
     */
    public function totalSales(
        ?Carbon $startDate,
        ?Carbon $endDate,
        VenteStatsFilter $filter,
    ): int {
        return (int) $this->buildBaseVenteQuery($startDate, $endDate, $filter)
            ->sum(DB::raw('price * quantity'));
    }

    /**
     * Compute the total profit for ventes within the given date range and filter constraints.
     *
     * This is the single source of truth for-profit computation in the application.
     * All dashboard, report, and API endpoints must use this method — never query ventes directly.
     *
     * @param  Carbon|null  $startDate  Inclusive start date; null means no lower bound (all-time).
     * @param  Carbon|null  $endDate  Inclusive end date; null means no upper bound (all-time).
     * @param  VenteStatsFilter  $filter  Additional constraints (paid status, commercial, car load, customer, type).
     * @return int Total profit amount in XOF.
     */
    public function totalEstimatedProfits(
        ?Carbon $startDate,
        ?Carbon $endDate,
        VenteStatsFilter $filter,
    ): int {
        return (int) $this->buildBaseVenteQuery($startDate, $endDate, $filter)
            ->sum('profit');
    }

    /**
     * Compute the total realized profit from payments within the given date range and filter constraints.
     *
     * "Realized profit" is the profit actually earned from money received, accounting for
     * partial payments on invoices. It is stored on payments.profit and populated automatically
     * at payment creation time via the Payment model's creating event.
     *
     * Formula per payment: invoice_total_profit / invoice_total × payment_amount
     *
     * This is the single source of truth for realized profit. Use totalEstimatedProfits() for potential
     * (full) profit across all ventes regardless of payment status.
     *
     * Note: paidStatus and type from VenteStatsFilter are not applicable here — every payment
     * is by definition a received amount. Only date range, commercialId, customerId, and
     * carLoadId are applied.
     *
     * @param  Carbon|null  $startDate  Inclusive start date (payment date); null means no lower bound.
     * @param  Carbon|null  $endDate  Inclusive end date (payment date); null means no upper bound.
     * @param  VenteStatsFilter  $filter  Constraints on commercial, customer, and car load.
     * @return int Total realized profit in XOF.
     */
    public function totalRealizedProfits(
        ?Carbon $startDate,
        ?Carbon $endDate,
        VenteStatsFilter $filter,
    ): int {
        return (int) $this->buildBasePaymentQuery($startDate, $endDate, $filter)
            ->sum('profit');
    }

    /**
     * Count sales invoices created within the given date range.
     */
    public function salesInvoicesCount(?Carbon $startDate, ?Carbon $endDate): int
    {
        return $this->buildBaseSalesInvoiceQuery($startDate, $endDate)->count();
    }

    /**
     * Count fully paid sales invoices within the given date range.
     */
    public function fullyPaidSalesInvoicesCount(?Carbon $startDate, ?Carbon $endDate): int
    {
        return $this->buildBaseSalesInvoiceQuery($startDate, $endDate)
            ->where('status', SalesInvoiceStatus::FullyPaid)
            ->count();
    }

    /**
     * Count partially paid sales invoices within the given date range.
     */
    public function partiallyPaidSalesInvoicesCount(?Carbon $startDate, ?Carbon $endDate): int
    {
        return $this->buildBaseSalesInvoiceQuery($startDate, $endDate)
            ->where('status', SalesInvoiceStatus::PartiallyPaid)
            ->count();
    }

    /**
     * Count unpaid sales invoices within the given date range.
     *
     * Both DRAFT (back-office, not yet issued) and ISSUED (sent to customer, awaiting
     * payment) are considered unpaid — neither has any payment recorded yet.
     */
    public function unpaidSalesInvoicesCount(?Carbon $startDate, ?Carbon $endDate): int
    {
        return $this->buildBaseSalesInvoiceQuery($startDate, $endDate)
            ->whereIn('status', [SalesInvoiceStatus::Draft, SalesInvoiceStatus::Issued])
            ->count();
    }

    // =========================================================================
    // Reporting
    // =========================================================================

    /**
     * Group a commercial's invoices by week and return debt summaries.
     */
    public function weeklyDebts(int $commercialId): Collection
    {
        $invoices = SalesInvoice::with(['customer', 'items.product'])
            ->where('commercial_id', $commercialId)
            ->get();

        return $invoices->groupBy(function (SalesInvoice $invoice) {
            $date = Carbon::parse($invoice->created_at);
            $weekStart = $date->startOfWeek();
            $weekEnd = $date->copy()->endOfWeek();

            return $weekStart->format('Y-m-d').'|'.$weekEnd->format('Y-m-d');
        })->map(function (Collection $weekInvoices, string $weekKey) {
            [$weekStart, $weekEnd] = explode('|', $weekKey);

            $weeklyTotal = $weekInvoices->sum('total_amount');
            $weeklyTotalPaid = $weekInvoices->sum('total_payments');

            return [
                'label' => 'Du '.Carbon::parse($weekStart)->locale('fr')->isoFormat('dddd D MMMM').
                          ' au '.Carbon::parse($weekEnd)->locale('fr')->isoFormat('dddd D MMMM YYYY'),
                'total' => $weeklyTotal,
                'total_paid' => $weeklyTotalPaid,
                'total_remaining' => $weeklyTotal - $weeklyTotalPaid,
                'invoices' => $weekInvoices->map(fn (SalesInvoice $invoice) => [
                    'id' => $invoice->id,
                    'created_at' => $invoice->created_at,
                    'customer' => [
                        'name' => $invoice->customer->name,
                        'phone_number' => $invoice->customer->phone_number,
                    ],
                    'total' => $invoice->total_amount,
                    'total_paid' => $invoice->total_payments,
                    'total_remaining' => $invoice->total_amount - $invoice->total_payments,
                ])->values(),
            ];
        })->values();
    }

    /**
     * Compute the activity report for a commercial over a given period.
     *
     * All financial totals are derived from stored columns on sales_invoices (total_amount,
     * total_payments), so results are always consistent with the invoice lifecycle and no
     * raw vente-level queries are needed.
     *
     * - totalSales: SUM(sales_invoices.total_amount) for invoices created in the period.
     * - totalPayments: SUM(payments.amount) for payments collected in the period.
     * - totalUnpaidAmount: SUM(total_amount - total_payments) on invoices created in the period.
     * - Payment method totals (Wave/OM/Cash) are derived from the "payments" table in a single
     *   grouped query; their sum equals totalPayments.
     * - Customer counts reflect customers CREATED in the period (not customers who bought).
     */
    public function buildCommercialActivityReport(
        Commercial $commercial,
        Carbon $startDate,
        Carbon $endDate,
    ): CommercialActivityReportDTO {
        $invoiceAggregates = SalesInvoice::where('commercial_id', $commercial->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_sales, COALESCE(SUM(total_amount - total_payments), 0) as total_unpaid')
            ->first();

        $totalSales = ! empty($invoiceAggregates->total_sales) ? (int) $invoiceAggregates->total_sales : 0;
        $totalUnpaidAmount = ! empty($invoiceAggregates->total_unpaid) ? (int) $invoiceAggregates->total_unpaid : 0;

        $paymentMethodBreakdown = Payment::whereHas(
            'salesInvoice',
            fn (Builder $invoiceQuery) => $invoiceQuery->where('commercial_id', $commercial->id)
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $totalPaymentsCash = (int) ($paymentMethodBreakdown[Vente::PAYMENT_METHOD_CASH] ?? 0);
        $totalPaymentsWave = (int) ($paymentMethodBreakdown[Vente::PAYMENT_METHOD_WAVE] ?? 0);
        $totalPaymentsOm = (int) ($paymentMethodBreakdown[Vente::PAYMENT_METHOD_OM] ?? 0);
        $totalPayments = (int) $paymentMethodBreakdown->sum();

        $newConfirmedCustomersCount = $commercial->customers()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->nonProspects()
            ->count();

        $newProspectCustomersCount = $commercial->customers()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->prospects()
            ->count();

        return new CommercialActivityReportDTO(
            totalSales: $totalSales,
            totalPayments: $totalPayments,
            newConfirmedCustomersCount: $newConfirmedCustomersCount,
            newProspectCustomersCount: $newProspectCustomersCount,
            totalUnpaidAmount: $totalUnpaidAmount,
            totalPaymentsWave: $totalPaymentsWave,
            totalPaymentsOm: $totalPaymentsOm,
            totalPaymentsCash: $totalPaymentsCash,
        );
    }

    /**
     * Compute all dashboard statistics for a given time window.
     *
     * This is the single entry point for every period variant (daily / weekly / monthly / all-time).
     * Financial totals are delegated to SalesInvoiceStatsService — never queried directly here.
     */
    public function buildStatsForPeriod(?Carbon $startDate, ?Carbon $endDate): DashboardStats
    {
        $allFilter = VenteStatsFilter::regardlessOfPaymentStatus();

        $customerQuery = Customer::query();
        $paymentQuery = Payment::query()->whereNotNull('sales_invoice_id');
        $depenseQuery = Depense::query();

        if ($startDate !== null) {
            $customerQuery->where('created_at', '>=', $startDate);
            $paymentQuery->where('created_at', '>=', $startDate);
            $depenseQuery->where('created_at', '>=', $startDate);
        }

        if ($endDate !== null) {
            $customerQuery->where('created_at', '<=', $endDate);
            $paymentQuery->where('created_at', '<=', $endDate);
            $depenseQuery->where('created_at', '<=', $endDate);
        }

        return new DashboardStats(
            totalCustomers: (clone $customerQuery)->count(),
            totalProspects: (clone $customerQuery)->prospects()->count(),
            totalConfirmedCustomers: (clone $customerQuery)->nonProspects()->count(),
            salesInvoicesCount: $this->salesInvoicesCount($startDate, $endDate),
            fullyPaidSalesInvoicesCount: $this->fullyPaidSalesInvoicesCount($startDate, $endDate),
            partiallyPaidSalesInvoicesCount: $this->partiallyPaidSalesInvoicesCount($startDate, $endDate),
            unpaidSalesInvoicesCount: $this->unpaidSalesInvoicesCount($startDate, $endDate),
            totalSales: $this->totalSales($startDate, $endDate, $allFilter),
            totalEstimatedProfit: $this->totalEstimatedProfits($startDate, $endDate, $allFilter),
            totalRealizedProfit: $this->totalRealizedProfits($startDate, $endDate, $allFilter),
            totalPaymentsReceived: (int) $paymentQuery->sum('amount'),
            totalExpenses: (int) $depenseQuery->sum('amount'),
        );
    }

    // =========================================================================
    // Private query builders
    // =========================================================================

    private function buildBaseInvoiceItemsQuery(SalesInvoice $invoice): Builder
    {
        return Vente::query()
            ->where('sales_invoice_id', $invoice->id);
    }

    private function buildBaseInvoicePaymentsQuery(SalesInvoice $invoice): Builder
    {
        return Payment::query()
            ->where('sales_invoice_id', $invoice->id);
    }

    private function buildBaseSalesInvoiceQuery(?Carbon $startDate, ?Carbon $endDate): Builder
    {
        $query = SalesInvoice::query();

        if ($startDate !== null) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query;
    }

    private function buildBasePaymentQuery(
        ?Carbon $startDate,
        ?Carbon $endDate,
        VenteStatsFilter $filter,
    ): Builder {
        $query = Payment::query()->whereNotNull('sales_invoice_id');

        if ($startDate !== null) {
            $query->where('created_at', '>=', $startDate->copy()->startOfDay());
        }

        if ($endDate !== null) {
            $query->where('created_at', '<=', $endDate->copy()->endOfDay());
        }

        $needsInvoiceScope = $filter->commercialId !== null
            || $filter->customerId !== null
            || $filter->carLoadId !== null;

        if ($needsInvoiceScope) {
            $query->whereHas('salesInvoice', function (Builder $invoiceQuery) use ($filter) {
                if ($filter->commercialId !== null) {
                    $invoiceQuery->where('commercial_id', $filter->commercialId);
                }

                if ($filter->customerId !== null) {
                    $invoiceQuery->where('customer_id', $filter->customerId);
                }

                if ($filter->carLoadId !== null) {
                    $invoiceQuery->where('car_load_id', $filter->carLoadId);
                }
            });
        }

        return $query;
    }

    private function buildBaseVenteQuery(
        ?Carbon $startDate,
        ?Carbon $endDate,
        VenteStatsFilter $filter,
    ): Builder {
        $query = Vente::query();

        if ($startDate !== null) {
            $query->whereDate('created_at', '>=', $startDate->copy()->startOfDay());
        }

        if ($endDate !== null) {
            $query->whereDate('created_at', '<=', $endDate->copy()->endOfDay());
        }

        match ($filter->paidStatus) {
            PaidStatus::PaidOnly => $query->where('paid', true),
            PaidStatus::UnpaidOnly => $query->where('paid', false),
            PaidStatus::All => null,
        };

        if ($filter->customerId !== null) {
            $query->where('customer_id', $filter->customerId);
        }

        if ($filter->type !== null) {
            $query->where('type', $filter->type);
        }

        $needsInvoiceScope = $filter->commercialId !== null || $filter->carLoadId !== null;

        if ($needsInvoiceScope) {
            $query->whereHas('salesInvoice', function (Builder $invoiceQuery) use ($filter) {
                if ($filter->commercialId !== null) {
                    $invoiceQuery->where('commercial_id', $filter->commercialId);
                }

                if ($filter->carLoadId !== null) {
                    $invoiceQuery->where('car_load_id', $filter->carLoadId);
                }
            });
        }

        return $query;
    }
}
