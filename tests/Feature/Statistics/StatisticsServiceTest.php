<?php

namespace Tests\Feature\Statistics;

use App\Data\Statistics\MonthlyActivitySummaryDTO;
use App\Data\Statistics\YearlyActivitySummaryDTO;
use App\Services\StatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for StatisticsService::buildMonthlyActivity().
 *
 * Every number shown on the Statistiques page comes from this service.
 * Wrong numbers = financial mis-reporting, so coverage must be exhaustive.
 *
 * Coverage:
 *  - Returns the correct number of DailyActivityDTO entries (one per calendar day)
 *  - Zero-activity days have all values set to 0 and isDeficit = false
 *  - Days with invoices aggregate total_sales, estimated_profit, commissions, delivery_cost correctly
 *  - Days with payments aggregate total_realized_profit correctly (keyed to payment date, not invoice date)
 *  - invoiceAverageTotal = totalSales / invoicesCount, rounded to int; 0 when no invoices
 *  - netProfit = totalRealizedProfit − totalCommissions − totalDeliveryCost
 *  - isDeficit is true when netProfit < 0
 *  - Monthly totals are the sum of all per-day values
 *  - activeDaysCount counts only days that had at least one invoice
 *  - averageDailySales = totalSales / activeDaysCount, 0 when no active days
 *  - averageInvoiceTotal = totalSales / totalInvoicesCount, 0 when no invoices
 *  - Records outside the requested month are excluded
 *  - Multiple invoices on the same day are summed correctly
 *  - Edge cases: entire month with no activity returns all-zero summary
 */
class StatisticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private StatisticsService $service;

    private int $phoneCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StatisticsService;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Insert a minimal SalesInvoice row with the given financial columns
     * and a specific created_at date. Returns the inserted invoice id.
     */
    private function createInvoiceOnDate(
        string $date,
        int $totalAmount = 0,
        int $totalEstimatedProfit = 0,
        int $estimatedCommercialCommission = 0,
        int $deliveryCost = 0,
    ): int {
        $commercialId = DB::table('commercials')->insertGetId([
            'name' => 'Stats Test Commercial '.$this->phoneCounter,
            'phone_number' => '77100'.str_pad($this->phoneCounter++, 5, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'salary' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Stats Test Customer '.$commercialId,
            'phone_number' => '76100'.str_pad($commercialId, 5, '0', STR_PAD_LEFT),
            'owner_number' => '0000000000',
            'gps_coordinates' => '0,0',
            'commercial_id' => $commercialId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('sales_invoices')->insertGetId([
            'customer_id' => $customerId,
            'status' => 'DRAFT',
            'total_amount' => $totalAmount,
            'total_estimated_profit' => $totalEstimatedProfit,
            'total_payments' => 0,
            'total_realized_profit' => 0,
            'estimated_commercial_commission' => $estimatedCommercialCommission,
            'delivery_cost' => $deliveryCost,
            'created_at' => $date.' 10:00:00',
            'updated_at' => $date.' 10:00:00',
        ]);
    }

    /**
     * Insert a minimal payment row linked to an invoice with a specific payment date.
     */
    private function createPaymentOnDate(int $invoiceId, string $date, int $profit): void
    {
        DB::table('payments')->insert([
            'sales_invoice_id' => $invoiceId,
            'amount' => 1000,
            'profit' => $profit,
            'payment_method' => 'CASH',
            'created_at' => $date.' 14:00:00',
            'updated_at' => $date.' 14:00:00',
        ]);
    }

    // ─── Structure tests ──────────────────────────────────────────────────────

    public function test_returns_one_daily_activity_entry_per_calendar_day(): void
    {
        $result = $this->service->buildMonthlyActivity(2026, 2);

        $this->assertInstanceOf(MonthlyActivitySummaryDTO::class, $result);
        $this->assertSame(28, $result->daysInMonth);
        $this->assertCount(28, $result->dailyActivity);
    }

    public function test_returns_correct_days_for_a_31_day_month(): void
    {
        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(31, $result->daysInMonth);
        $this->assertCount(31, $result->dailyActivity);
        $this->assertSame('2026-03-01', $result->dailyActivity[0]->date);
        $this->assertSame('2026-03-31', $result->dailyActivity[30]->date);
    }

    public function test_zero_activity_month_returns_all_zeros(): void
    {
        $result = $this->service->buildMonthlyActivity(2026, 1);

        $this->assertSame(0, $result->totalSales);
        $this->assertSame(0, $result->totalInvoicesCount);
        $this->assertSame(0, $result->activeDaysCount);
        $this->assertSame(0, $result->netProfit);
        $this->assertSame(0, $result->averageDailySales);
        $this->assertSame(0, $result->averageInvoiceTotal);

        foreach ($result->dailyActivity as $day) {
            $this->assertSame(0, $day->invoicesCount);
            $this->assertSame(0, $day->totalSales);
            $this->assertSame(0, $day->netProfit);
            $this->assertFalse($day->isDeficit);
        }
    }

    // ─── Invoice aggregation ──────────────────────────────────────────────────

    public function test_invoice_total_sales_aggregated_on_correct_day(): void
    {
        $this->createInvoiceOnDate('2026-03-10', totalAmount: 150_000);
        $this->createInvoiceOnDate('2026-03-10', totalAmount: 80_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $day10 = $result->dailyActivity[9]; // day 10 is at index 9
        $this->assertSame('2026-03-10', $day10->date);
        $this->assertSame(2, $day10->invoicesCount);
        $this->assertSame(230_000, $day10->totalSales);
    }

    public function test_invoice_estimated_profit_aggregated_correctly(): void
    {
        $this->createInvoiceOnDate('2026-03-05', totalAmount: 100_000, totalEstimatedProfit: 20_000);
        $this->createInvoiceOnDate('2026-03-05', totalAmount: 50_000, totalEstimatedProfit: 8_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $day5 = $result->dailyActivity[4];
        $this->assertSame(28_000, $day5->totalEstimatedProfit);
    }

    public function test_invoice_commissions_aggregated_correctly(): void
    {
        $this->createInvoiceOnDate('2026-03-15', estimatedCommercialCommission: 5_000);
        $this->createInvoiceOnDate('2026-03-15', estimatedCommercialCommission: 3_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $day15 = $result->dailyActivity[14];
        $this->assertSame(8_000, $day15->totalCommissions);
    }

    public function test_invoice_delivery_cost_aggregated_correctly(): void
    {
        $this->createInvoiceOnDate('2026-03-20', deliveryCost: 2_000);
        $this->createInvoiceOnDate('2026-03-20', deliveryCost: 1_500);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $day20 = $result->dailyActivity[19];
        $this->assertSame(3_500, $day20->totalDeliveryCost);
    }

    // ─── Payment aggregation ──────────────────────────────────────────────────

    public function test_realized_profit_keyed_to_payment_date_not_invoice_date(): void
    {
        // Invoice created on day 3, payment received on day 10
        $invoiceId = $this->createInvoiceOnDate('2026-03-03', totalAmount: 100_000);
        $this->createPaymentOnDate($invoiceId, '2026-03-10', profit: 15_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        // Day 3 should have 0 realized profit (invoice was created here, not paid)
        $this->assertSame(0, $result->dailyActivity[2]->totalRealizedProfit);

        // Day 10 should have 15 000 realized profit (payment received here)
        $this->assertSame(15_000, $result->dailyActivity[9]->totalRealizedProfit);
    }

    public function test_multiple_payments_on_same_day_are_summed(): void
    {
        $invoiceId1 = $this->createInvoiceOnDate('2026-03-01', totalAmount: 100_000);
        $invoiceId2 = $this->createInvoiceOnDate('2026-03-01', totalAmount: 50_000);
        $this->createPaymentOnDate($invoiceId1, '2026-03-12', profit: 10_000);
        $this->createPaymentOnDate($invoiceId2, '2026-03-12', profit: 6_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(16_000, $result->dailyActivity[11]->totalRealizedProfit);
    }

    // ─── Per-day computed fields ──────────────────────────────────────────────

    public function test_net_profit_equals_realized_minus_commissions_minus_delivery(): void
    {
        $invoiceId = $this->createInvoiceOnDate(
            '2026-03-07',
            totalAmount: 100_000,
            estimatedCommercialCommission: 3_000,
            deliveryCost: 1_000,
        );
        $this->createPaymentOnDate($invoiceId, '2026-03-07', profit: 12_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $day7 = $result->dailyActivity[6];
        // Net = 12 000 (realized) - 3 000 (commissions) - 1 000 (delivery) = 8 000
        $this->assertSame(8_000, $day7->netProfit);
        $this->assertFalse($day7->isDeficit);
    }

    public function test_is_deficit_true_when_net_profit_is_negative(): void
    {
        $invoiceId = $this->createInvoiceOnDate(
            '2026-03-08',
            estimatedCommercialCommission: 5_000,
            deliveryCost: 4_000,
        );
        $this->createPaymentOnDate($invoiceId, '2026-03-08', profit: 2_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $day8 = $result->dailyActivity[7];
        // Net = 2 000 - 5 000 - 4 000 = -7 000 (deficit)
        $this->assertSame(-7_000, $day8->netProfit);
        $this->assertTrue($day8->isDeficit);
    }

    public function test_invoice_average_total_is_rounded_sales_divided_by_count(): void
    {
        // 3 invoices totalling 100 000 → average = 33 333 (rounded)
        $this->createInvoiceOnDate('2026-03-14', totalAmount: 40_000);
        $this->createInvoiceOnDate('2026-03-14', totalAmount: 30_000);
        $this->createInvoiceOnDate('2026-03-14', totalAmount: 30_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $day14 = $result->dailyActivity[13];
        $this->assertSame(3, $day14->invoicesCount);
        $this->assertSame(33_333, $day14->invoiceAverageTotal);
    }

    public function test_invoice_average_total_is_zero_when_no_invoices(): void
    {
        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(0, $result->dailyActivity[0]->invoiceAverageTotal);
    }

    // ─── Monthly totals ───────────────────────────────────────────────────────

    public function test_monthly_totals_sum_all_daily_values(): void
    {
        $this->createInvoiceOnDate('2026-03-01', totalAmount: 200_000, totalEstimatedProfit: 40_000);
        $this->createInvoiceOnDate('2026-03-15', totalAmount: 150_000, totalEstimatedProfit: 30_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(350_000, $result->totalSales);
        $this->assertSame(70_000, $result->totalEstimatedProfit);
        $this->assertSame(2, $result->totalInvoicesCount);
    }

    public function test_monthly_active_days_count_excludes_zero_invoice_days(): void
    {
        $this->createInvoiceOnDate('2026-03-01');
        $this->createInvoiceOnDate('2026-03-01'); // same day as above
        $this->createInvoiceOnDate('2026-03-05');

        $result = $this->service->buildMonthlyActivity(2026, 3);

        // Only 2 distinct days had invoices
        $this->assertSame(2, $result->activeDaysCount);
    }

    public function test_average_daily_sales_uses_active_days_only(): void
    {
        // 300 000 total sales on 2 active days → average = 150 000
        $this->createInvoiceOnDate('2026-03-03', totalAmount: 200_000);
        $this->createInvoiceOnDate('2026-03-22', totalAmount: 100_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(150_000, $result->averageDailySales);
    }

    public function test_average_daily_sales_is_zero_when_no_active_days(): void
    {
        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(0, $result->averageDailySales);
    }

    public function test_average_invoice_total_uses_all_invoices(): void
    {
        // 2 invoices totalling 300 000 → average = 150 000
        $this->createInvoiceOnDate('2026-03-10', totalAmount: 200_000);
        $this->createInvoiceOnDate('2026-03-20', totalAmount: 100_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(150_000, $result->averageInvoiceTotal);
    }

    public function test_monthly_net_profit_is_realized_minus_commissions_minus_delivery(): void
    {
        $invoiceId = $this->createInvoiceOnDate(
            '2026-03-01',
            estimatedCommercialCommission: 4_000,
            deliveryCost: 2_000,
        );
        $this->createPaymentOnDate($invoiceId, '2026-03-01', profit: 20_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        // 20 000 - 4 000 - 2 000 = 14 000
        $this->assertSame(14_000, $result->netProfit);
    }

    // ─── Isolation ───────────────────────────────────────────────────────────

    public function test_invoices_outside_the_requested_month_are_excluded(): void
    {
        // Invoice in February and April — only March should appear in March summary
        $this->createInvoiceOnDate('2026-02-28', totalAmount: 500_000);
        $this->createInvoiceOnDate('2026-04-01', totalAmount: 300_000);
        $this->createInvoiceOnDate('2026-03-15', totalAmount: 100_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(100_000, $result->totalSales);
        $this->assertSame(1, $result->totalInvoicesCount);
    }

    public function test_payments_outside_the_requested_month_are_excluded(): void
    {
        $invoiceId = $this->createInvoiceOnDate('2026-02-01');
        // Payment received in February and April
        $this->createPaymentOnDate($invoiceId, '2026-02-10', profit: 10_000);
        $this->createPaymentOnDate($invoiceId, '2026-04-05', profit: 8_000);

        $result = $this->service->buildMonthlyActivity(2026, 3);

        $this->assertSame(0, $result->totalRealizedProfit);
    }

    // ─── Yearly view ─────────────────────────────────────────────────────────

    public function test_yearly_activity_always_returns_twelve_monthly_totals(): void
    {
        $result = $this->service->buildYearlyActivity(2026);

        $this->assertInstanceOf(YearlyActivitySummaryDTO::class, $result);
        $this->assertCount(12, $result->monthlyTotals);
        $this->assertSame(1, $result->monthlyTotals[0]->monthNumber);
        $this->assertSame(12, $result->monthlyTotals[11]->monthNumber);
    }

    public function test_yearly_activity_with_no_data_returns_all_zeros(): void
    {
        $result = $this->service->buildYearlyActivity(2025);

        $this->assertSame(0, $result->totalSales);
        $this->assertSame(0, $result->totalInvoicesCount);
        $this->assertSame(0, $result->activeMonthsCount);
        $this->assertSame(0, $result->averageMonthlySales);
        $this->assertSame(0, $result->averageInvoiceTotal);
        $this->assertSame(0, $result->netProfit);

        foreach ($result->monthlyTotals as $monthDto) {
            $this->assertSame(0, $monthDto->invoicesCount);
            $this->assertSame(0, $monthDto->totalSales);
            $this->assertSame(0, $monthDto->netProfit);
            $this->assertFalse($monthDto->isDeficit);
        }
    }

    public function test_yearly_invoice_totals_aggregated_into_correct_month(): void
    {
        $this->createInvoiceOnDate('2026-03-10', totalAmount: 200_000, totalEstimatedProfit: 40_000);
        $this->createInvoiceOnDate('2026-03-20', totalAmount: 100_000, totalEstimatedProfit: 20_000);
        $this->createInvoiceOnDate('2026-07-05', totalAmount: 500_000, totalEstimatedProfit: 80_000);

        $result = $this->service->buildYearlyActivity(2026);

        $march = $result->monthlyTotals[2]; // month 3 = index 2
        $this->assertSame(300_000, $march->totalSales);
        $this->assertSame(60_000, $march->totalEstimatedProfit);
        $this->assertSame(2, $march->invoicesCount);

        $july = $result->monthlyTotals[6]; // month 7 = index 6
        $this->assertSame(500_000, $july->totalSales);
        $this->assertSame(1, $july->invoicesCount);

        // Months with no invoices must be zero
        $this->assertSame(0, $result->monthlyTotals[0]->totalSales); // January
    }

    public function test_yearly_realized_profit_keyed_to_payment_month(): void
    {
        // Invoice created in March, paid in June
        $invoiceId = $this->createInvoiceOnDate('2026-03-01');
        $this->createPaymentOnDate($invoiceId, '2026-06-15', profit: 25_000);

        $result = $this->service->buildYearlyActivity(2026);

        // March has an invoice but zero realized profit (payment arrived in June)
        $this->assertSame(0, $result->monthlyTotals[2]->totalRealizedProfit);
        // June has 25 000 realized profit
        $this->assertSame(25_000, $result->monthlyTotals[5]->totalRealizedProfit);
    }

    public function test_yearly_net_profit_per_month_equals_realized_minus_commissions_minus_delivery(): void
    {
        $invoiceId = $this->createInvoiceOnDate(
            '2026-05-10',
            estimatedCommercialCommission: 6_000,
            deliveryCost: 2_000,
        );
        $this->createPaymentOnDate($invoiceId, '2026-05-10', profit: 18_000);

        $result = $this->service->buildYearlyActivity(2026);

        $may = $result->monthlyTotals[4]; // month 5 = index 4
        // 18 000 - 6 000 - 2 000 = 10 000
        $this->assertSame(10_000, $may->netProfit);
        $this->assertFalse($may->isDeficit);
    }

    public function test_yearly_is_deficit_true_when_month_net_profit_is_negative(): void
    {
        $invoiceId = $this->createInvoiceOnDate(
            '2026-09-01',
            estimatedCommercialCommission: 10_000,
            deliveryCost: 5_000,
        );
        $this->createPaymentOnDate($invoiceId, '2026-09-01', profit: 3_000);

        $result = $this->service->buildYearlyActivity(2026);

        $september = $result->monthlyTotals[8]; // month 9 = index 8
        // 3 000 - 10 000 - 5 000 = -12 000
        $this->assertSame(-12_000, $september->netProfit);
        $this->assertTrue($september->isDeficit);
    }

    public function test_yearly_totals_sum_all_twelve_months(): void
    {
        $this->createInvoiceOnDate('2026-01-15', totalAmount: 100_000);
        $this->createInvoiceOnDate('2026-06-10', totalAmount: 200_000);
        $this->createInvoiceOnDate('2026-12-25', totalAmount: 300_000);

        $result = $this->service->buildYearlyActivity(2026);

        $this->assertSame(600_000, $result->totalSales);
        $this->assertSame(3, $result->totalInvoicesCount);
        $this->assertSame(3, $result->activeMonthsCount);
    }

    public function test_yearly_average_monthly_sales_uses_active_months_only(): void
    {
        // 600 000 across 3 active months → average = 200 000
        $this->createInvoiceOnDate('2026-02-01', totalAmount: 300_000);
        $this->createInvoiceOnDate('2026-08-01', totalAmount: 200_000);
        $this->createInvoiceOnDate('2026-11-01', totalAmount: 100_000);

        $result = $this->service->buildYearlyActivity(2026);

        $this->assertSame(200_000, $result->averageMonthlySales);
    }

    public function test_yearly_active_days_count_per_month_is_correct(): void
    {
        // 3 invoices on 2 distinct days in March
        $this->createInvoiceOnDate('2026-03-05');
        $this->createInvoiceOnDate('2026-03-05'); // same day
        $this->createInvoiceOnDate('2026-03-20');

        $result = $this->service->buildYearlyActivity(2026);

        $march = $result->monthlyTotals[2];
        $this->assertSame(3, $march->invoicesCount);
        $this->assertSame(2, $march->activeDaysCount);
    }

    public function test_yearly_activity_excludes_records_from_other_years(): void
    {
        $this->createInvoiceOnDate('2025-12-31', totalAmount: 999_000);
        $this->createInvoiceOnDate('2027-01-01', totalAmount: 888_000);
        $this->createInvoiceOnDate('2026-06-15', totalAmount: 100_000);

        $result = $this->service->buildYearlyActivity(2026);

        $this->assertSame(100_000, $result->totalSales);
        $this->assertSame(1, $result->totalInvoicesCount);
    }
}
