<?php

namespace App\Http\Controllers;

use App\Data\Vente\VenteStatsFilter;
use App\Models\Commercial;
use App\Services\AccountReceivablesMetricsService;
use App\Services\KPIReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceController extends Controller
{
    public function __construct(
        private readonly KPIReportService $kpiReportService,
        private readonly AccountReceivablesMetricsService $accountReceivablesMetricsService,
    ) {}

    public function index(Request $request): Response
    {
        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->query('start_date'))->startOfDay()
            : now()->startOfMonth()->startOfDay();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->query('end_date'))->endOfDay()
            : now()->endOfDay();

        $selectedCommercialId = $request->filled('commercial_id')
            ? (int) $request->query('commercial_id')
            : null;

        $performanceData = null;

        if ($selectedCommercialId !== null) {
            $commercial = Commercial::find($selectedCommercialId);

            if ($commercial !== null) {
                $filter = VenteStatsFilter::regardlessOfPaymentStatus()
                    ->thatAreMadeByCommercial($commercial->id)
                    ->inDateInterval($startDate, $endDate);

                $salesMetrics = $this->kpiReportService->buildSalesPerformanceMetrics($filter);
                $coverageMetrics = $this->kpiReportService->buildCustomerCoverageMetrics($filter);
                $productMixMetrics = $this->kpiReportService->buildProductMixMetrics($filter);
                $collectionMetrics = $this->accountReceivablesMetricsService->buildMetrics($filter)->metrics;

                $performanceData = [
                    'commercial_id' => $commercial->id,
                    'commercial_name' => $commercial->name,
                    'sales' => [
                        'total_revenue' => $salesMetrics->totalRevenue,
                        'total_invoices_count' => $salesMetrics->totalInvoicesCount,
                        'average_basket_size' => $salesMetrics->averageBasketSize,
                        'average_revenue_per_customer' => $salesMetrics->averageRevenuePerCustomer,
                        'unique_customers_served_count' => $salesMetrics->uniqueCustomersServedCount,
                        'profit_generated' => $salesMetrics->profitGenerated,
                        'total_payments' => $salesMetrics->totalPayments,
                    ],
                    'coverage' => [
                        'active_customers_count' => $coverageMetrics->activeCustomersCount,
                        'visited_customers_count' => $coverageMetrics->visitedCustomersCount,
                        'returning_customers_count' => $coverageMetrics->returningCustomersCount,
                        'new_confirmed_customers_count' => $coverageMetrics->newConfirmedCustomersCount,
                        'new_prospect_customers_count' => $coverageMetrics->newProspectCustomersCount,
                        'prospects_converted_to_confirmed_count' => $coverageMetrics->prospectsConvertedToConfirmedCount,
                        'churning_customers_count' => $coverageMetrics->churningCustomersCount,
                        'visit_strike_rate' => $coverageMetrics->visitStrikeRate,
                        'acquisition_strike_rate' => $coverageMetrics->acquisitionStrikeRate,
                        'customer_retention_rate' => $coverageMetrics->customerRetentionRate,
                        'churning_rate' => $coverageMetrics->churningRate,
                    ],
                    'product_mix' => [
                        'push_score' => $productMixMetrics->pushScore,
                        'average_product_categories_per_invoice' => $productMixMetrics->averageProductCategoriesPerInvoice,
                        'total_distinct_products_sold_count' => $productMixMetrics->totalDistinctProductsSoldCount,
                        'total_distinct_categories_sold_count' => $productMixMetrics->totalDistinctCategoriesSoldCount,
                    ],
                    'collection' => [
                        'total_outstanding_amount' => $collectionMetrics->totalOutstandingAmount,
                        'fully_paid_invoices_count' => $collectionMetrics->fullyPaidInvoicesCount,
                        'total_invoices_count' => $collectionMetrics->totalInvoicesCount,
                        'on_time_invoices_count' => $collectionMetrics->onTimeInvoicesCount,
                        'on_time_payment_rate' => $collectionMetrics->onTimePaymentRate,
                        'collection_effectiveness_index' => $collectionMetrics->collectionEffectivenessIndex,
                        'average_days_to_payment' => $collectionMetrics->averageDaysToPayment,
                        'median_days_to_payment' => $collectionMetrics->medianDaysToPayment,
                        'average_days_delinquent' => $collectionMetrics->averageDaysDelinquent,
                        'median_days_delinquent' => $collectionMetrics->medianDaysDelinquent,
                    ],
                ];
            }
        }

        return Inertia::render('Performances/Index', [
            'commerciaux' => Commercial::query()->orderBy('name')->get(['id', 'name']),
            'selectedCommercialId' => $selectedCommercialId,
            'performanceData' => $performanceData,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
        ]);
    }
}
