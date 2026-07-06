<?php

namespace Tests\Feature\Feature\KPI;

use App\Data\Vente\VenteStatsFilter;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Services\KPIReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for KPIReportService::buildSalesPerformanceMetrics().
 *
 * Covers all fields including profitGenerated and totalPayments, which are
 * aggregated from denormalized SalesInvoice columns (total_estimated_profit
 * and total_payments respectively).
 */
class KPIReportServiceSalesPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private KPIReportService $kpiReportService;

    private Commercial $defaultCommercial;

    private Customer $defaultCustomer;

    private VenteStatsFilter $defaultFilter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kpiReportService = app(KPIReportService::class);

        $team = Team::create([
            'name' => 'Test Team',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->defaultCommercial = Commercial::create([
            'name' => 'Test Commercial',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $team->id,
        ]);

        $this->defaultCustomer = Customer::create([
            'name' => 'Test Customer',
            'address' => 'Test Address',
            'phone_number' => '221700000002',
            'owner_number' => '221700000003',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->defaultCommercial->id,
        ]);

        $this->defaultFilter = VenteStatsFilter::new()
            ->thatAreMadeByCommercial($this->defaultCommercial->id)
            ->inDateInterval(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createInvoice(
        int $totalAmount = 0,
        int $totalEstimatedProfit = 0,
        int $totalPayments = 0,
        ?Carbon $createdAt = null,
        ?int $commercialId = null,
    ): SalesInvoice {
        $invoice = SalesInvoice::create([
            'customer_id' => $this->defaultCustomer->id,
            'commercial_id' => $commercialId ?? $this->defaultCommercial->id,
        ]);

        // Financial columns are not in $fillable — bypass mass-assignment via DB::table()
        // so we can set precise test values without triggering recalculateStoredTotals().
        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'total_amount' => $totalAmount,
            'total_estimated_profit' => $totalEstimatedProfit,
            'total_payments' => $totalPayments,
            'created_at' => $createdAt ?? $invoice->created_at,
        ]);

        return $invoice->fresh();
    }

    // =========================================================================
    // profitGenerated — SUM of total_estimated_profit across invoices in scope
    // =========================================================================

    public function test_profit_generated_is_zero_when_no_invoices_exist(): void
    {
        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(0, $metrics->profitGenerated);
    }

    public function test_profit_generated_sums_total_estimated_profit_across_all_invoices(): void
    {
        $this->createInvoice(totalAmount: 10_000, totalEstimatedProfit: 2_000);
        $this->createInvoice(totalAmount: 5_000, totalEstimatedProfit: 800);
        $this->createInvoice(totalAmount: 20_000, totalEstimatedProfit: 4_500);

        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(7_300, $metrics->profitGenerated);
    }

    public function test_profit_generated_excludes_invoices_outside_date_range(): void
    {
        $this->createInvoice(totalEstimatedProfit: 3_000, createdAt: Carbon::now()->startOfMonth());
        $this->createInvoice(totalEstimatedProfit: 1_500, createdAt: Carbon::now()->subMonth());

        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(3_000, $metrics->profitGenerated);
    }

    public function test_profit_generated_excludes_invoices_from_other_commercials(): void
    {
        $otherCommercial = Commercial::create([
            'name' => 'Other Commercial',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $this->defaultCommercial->team_id,
        ]);

        $this->createInvoice(totalEstimatedProfit: 2_000);
        $this->createInvoice(totalEstimatedProfit: 5_000, commercialId: $otherCommercial->id);

        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(2_000, $metrics->profitGenerated);
    }

    // =========================================================================
    // totalPayments — SUM of total_payments across invoices in scope
    // =========================================================================

    public function test_total_payments_is_zero_when_no_invoices_exist(): void
    {
        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(0, $metrics->totalPayments);
    }

    public function test_total_payments_sums_collected_amounts_across_all_invoices(): void
    {
        $this->createInvoice(totalAmount: 10_000, totalPayments: 10_000);
        $this->createInvoice(totalAmount: 5_000, totalPayments: 2_500);
        $this->createInvoice(totalAmount: 8_000, totalPayments: 0);

        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(12_500, $metrics->totalPayments);
    }

    public function test_total_payments_excludes_invoices_outside_date_range(): void
    {
        $this->createInvoice(totalPayments: 6_000, createdAt: Carbon::now()->startOfMonth());
        $this->createInvoice(totalPayments: 3_000, createdAt: Carbon::now()->subMonth());

        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(6_000, $metrics->totalPayments);
    }

    public function test_total_payments_excludes_invoices_from_other_commercials(): void
    {
        $otherCommercial = Commercial::create([
            'name' => 'Other Commercial 2',
            'phone_number' => '221700000098',
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $this->defaultCommercial->team_id,
        ]);

        $this->createInvoice(totalPayments: 4_000);
        $this->createInvoice(totalPayments: 9_000, commercialId: $otherCommercial->id);

        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(4_000, $metrics->totalPayments);
    }

    // =========================================================================
    // Combined — all three financial fields together
    // =========================================================================

    public function test_revenue_profit_generated_and_total_payments_are_all_correct_together(): void
    {
        $this->createInvoice(totalAmount: 10_000, totalEstimatedProfit: 2_500, totalPayments: 7_000);
        $this->createInvoice(totalAmount: 15_000, totalEstimatedProfit: 3_750, totalPayments: 15_000);

        $metrics = $this->kpiReportService->buildSalesPerformanceMetrics($this->defaultFilter);

        $this->assertSame(25_000, $metrics->totalRevenue);
        $this->assertSame(6_250, $metrics->profitGenerated);
        $this->assertSame(22_000, $metrics->totalPayments);
    }
}
