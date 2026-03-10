<?php

/** @noinspection UnknownColumnInspection */

namespace App\Services;

use App\Data\ActivityReport\CommercialActivityReportDTO;
use App\Data\Dashboard\DashboardStats;
use App\Data\Vente\PaidStatus;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Depense;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

readonly class SalesInvoiceService
{
    public function __construct(
        private CarLoadService $carLoadService
    ) {}

    /** @throws  InsufficientStockException|Throwable */
    public function createSalesInvoice(array $data): SalesInvoice
    {
        return DB::transaction(function () use ($data) {
            // Create the sales invoice
            $user = auth()->user();
            $user->load('commercial');

            $salesInvoice = SalesInvoice::create([
                'customer_id' => $data['customer_id'],
                'invoice_number' => 'F'.date('Ymd').'-'.str_pad(SalesInvoice::count() + 1, 4, '0', STR_PAD_LEFT),
                'comment' => 'Facture de Vente',
                'should_be_paid_at' => $data['should_be_paid_at'] ?? null,
                'commercial_id' => $user->commercial->id,
                'status' => SalesInvoiceStatus::Draft,
            ]);
            $salesInvoice->refresh();

            // Add items to the invoice and update stock
            $itemsArray = [];
            foreach ($data['items'] as $item) {

                $salesInvoiceItem = new Vente([
                    'sales_invoice_id' => $salesInvoice->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'type' => 'INVOICE_ITEM',
                ]);
                $salesInvoiceItem->calculateProfit();

                $itemsArray[] = $salesInvoiceItem;

            }
            $salesInvoice->items()->saveMany($itemsArray);
            //  move the stock
            $currentCarLoad = $this->carLoadService->getCurrentCarLoadForTeam($user->commercial->team);
            if ($currentCarLoad === null) {
                throw new UnprocessableEntityHttpException(
                    'Pour pourvoir faire une vente, il faut un chargement de véhicule attribué à votre équipe !'
                );
            }
            foreach ($itemsArray as $item) {
                $this->carLoadService->decreaseProductStockInCarLoadUsingFifo($item->product, $item->quantity, $currentCarLoad);
            }

            // If paid, create the payment, then explicitly transition to FULLY_PAID.
            // markAsFullyPaid() is the only authorised path to FULLY_PAID status.
            if ($data['paid']) {
                $totalAmount = $this->calculateTotalAmountForInvoice($salesInvoice);
                $this->paySalesInvoice($salesInvoice, [
                    'sales_invoice_id' => $salesInvoice->id,
                    'amount' => $totalAmount,
                    'payment_method' => $data['payment_method'],
                ], request()->user()->id);
            }

            $customer = Customer::withoutEagerLoads()->findOrFail($data['customer_id']);
            //            // check customer has current visite also check if it is a prospect
            //            $customerVisit = $customer->visits()->where('status', CustomerVisit::STATUS_PLANNED)->orderBy('created_at', 'asc')
            //                ->first();
            //            $customerVisit?->complete([
            //                'notes' => 'Visite complété après enregistrement facture',
            //                'gps_coordinates' => $customer->gps_coordinates,
            //                'resulted_in_sale' => true,
            //            ]);
            if ($customer->is_prospect) {
                $customer->is_prospect = false;
                $customer->save();
            }

            return $salesInvoice;
        });
    }

    /** @throws Throwable */
    public function paySalesInvoice(SalesInvoice $salesInvoice, array $validatedData, int $userId): bool
    {
        return DB::transaction(function () use ($salesInvoice, $validatedData, $userId) {

            $salesInvoice->payments()->create([
                'amount' => $validatedData['amount'],
                'payment_method' => $validatedData['payment_method'],
                'comment' => $validatedData['comment'] ?? null,
                'user_id' => $userId,
            ]);
            $salesInvoice->recalculateStoredTotals();
            $totalPaid = $this->calculateTotalPaymentsForInvoice($salesInvoice);
            $invoiceTotal = $this->calculateTotalAmountForInvoice($salesInvoice);

            if ($totalPaid >= $invoiceTotal) {
                $salesInvoice->markAsFullyPaid();
            }

            return true;
        });
    }

    public function weeklyDebts(int $commercial_id)
    {
        $invoices = SalesInvoice::with(['customer', 'items.product'])
            ->where('commercial_id', $commercial_id)
            ->get();

        return $invoices->groupBy(function ($invoice) {
            $date = Carbon::parse($invoice->created_at);
            $weekStart = $date->startOfWeek();
            $weekEnd = $date->copy()->endOfWeek();

            return $weekStart->format('Y-m-d').'|'.$weekEnd->format('Y-m-d');
        })->map(function ($weekInvoices, $weekKey) {
            [$weekStart, $weekEnd] = explode('|', $weekKey);

            $weeklyTotal = $weekInvoices->sum('total_amount');
            $weeklyTotalPaid = $weekInvoices->sum('total_payments');

            /**
             * @var $weekInvoices Collection
             */
            return [
                'label' => 'Du '.Carbon::parse($weekStart)->locale('fr')->isoFormat('dddd D MMMM').
                          ' au '.Carbon::parse($weekEnd)->locale('fr')->isoFormat('dddd D MMMM YYYY'),
                'total' => $weeklyTotal,
                'total_paid' => $weeklyTotalPaid,
                'total_remaining' => $weeklyTotal - $weeklyTotalPaid,

                'invoices' => $weekInvoices->map(function (SalesInvoice $invoice) {
                    return [
                        'id' => $invoice->id,
                        'created_at' => $invoice->created_at,
                        'customer' => [
                            'name' => $invoice->customer->name,
                            'phone_number' => $invoice->customer->phone_number,
                        ],
                        'total' => $invoice->total_amount,
                        'total_paid' => $invoice->total_payments,
                        'total_remaining' => $invoice->total_amount - $invoice->total_payments,
                    ];
                })->values(),
            ];
        })->values();
    }

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
     * Count draft (unpaid) sales invoices within the given date range.
     */
    public function unpaidSalesInvoicesCount(?Carbon $startDate, ?Carbon $endDate): int
    {
        return $this->buildBaseSalesInvoiceQuery($startDate, $endDate)
            ->where('status', SalesInvoiceStatus::Draft)
            ->count();
    }

    // =========================================================================
    // Per-invoice calculation helpers (used by SalesInvoice::recalculateStoredTotals)
    // =========================================================================

    /**
     * Build a base query scoped to the INVOICE_ITEM ventes of a specific invoice.
     * calculateTotalAmountForInvoice and calculateTotalEstimatedProfitForInvoice
     * both run their aggregates on top of this query.
     */
    private function buildBaseInvoiceItemsQuery(SalesInvoice $invoice): Builder
    {
        return Vente::query()
            ->where('sales_invoice_id', $invoice->id);
    }

    /**
     * Build a base query scoped to the payments of a specific invoice.
     * calculateTotalPaymentsForInvoice and calculateTotalRealizedProfitForInvoice
     * both run their aggregates on top of this query.
     */
    private function buildBaseInvoicePaymentsQuery(SalesInvoice $invoice): Builder
    {
        return Payment::query()
            ->where('sales_invoice_id', $invoice->id);
    }

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

    /**
     * Build a base SalesInvoice query scoped to the given date range.
     */
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

    /**
     * Build a base Payment query scoped to invoice payments with date and filter constraints.
     * totalRealizedProfits runs its aggregate on top of this query.
     */
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

    /**
     * Build a base Vente query with all date and filter constraints applied.
     * Both totalSales and totalEstimatedProfits run their aggregate on top of this query.
     */
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

    /**
     * Compute the activity report for a commercial over a given period.
     *
     * All financial totals are derived from stored columns on sales_invoices (total_amount,
     * total_payments) so results are always consistent with the invoice lifecycle and no
     * raw vente-level queries are needed.
     *
     * - totalSales:       SUM(sales_invoices.total_amount) for invoices created in the period.
     * - totalPayments:    SUM(payments.amount) for payments collected in the period.
     * - totalUnpaidAmount: SUM(total_amount - total_payments) on invoices created in the period.
     * - Payment method totals (Wave/OM/Cash) are derived from the payments table in a single
     *   grouped query; their sum equals totalPayments.
     * - Customer counts reflect customers CREATED in the period (not customers who bought).
     */
    public function buildCommercialActivityReport(
        Commercial $commercial,
        Carbon $startDate,
        Carbon $endDate,
    ): CommercialActivityReportDTO {
        // ── Invoice stored totals (single query on sales_invoices) ──────────────────
        $invoiceAggregates = SalesInvoice::where('commercial_id', $commercial->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_sales, COALESCE(SUM(total_amount - total_payments), 0) as total_unpaid')
            ->first();

        $totalSales = (int) $invoiceAggregates->total_sales;
        $totalUnpaidAmount = (int) $invoiceAggregates->total_unpaid;

        // ── Payment method breakdown (single grouped query on payments) ───────────
        // Scoped to payments collected in the period for this commercial's invoices.
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

        // Sum across all methods (including any unlabelled payments) for the authoritative total.
        $totalPayments = (int) $paymentMethodBreakdown->sum();

        // ── Customer counts (customers CREATED in the period) ────────────────────
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
     * Financial totals are delegated to SalesInvoiceService — never queried directly here.
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
}
