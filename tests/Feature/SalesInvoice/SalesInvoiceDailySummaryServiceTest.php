<?php

namespace Tests\Feature\SalesInvoice;

use App\Data\SalesInvoice\SalesInvoicesDailyTotalsDTO;
use App\Enums\SalesInvoiceStatus;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Services\DailySalesInvoicesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Tests for SalesInvoiceDailySummaryService and associated DTOs.
 *
 * Verifies that:
 *  – getDailySummaries() returns only invoices for the requested date
 *  – filters (commercial, paid_status) are applied correctly
 *  – SalesInvoiceDailySummaryDTO carries the correct field values
 *  – SalesInvoicesDailyTotalsDTO sums are correct
 *  – netProfit() = total_realized_profit − commissions − delivery_cost
 */
class SalesInvoiceDailySummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailySalesInvoicesService $service;

    private Customer $defaultCustomer;

    private Commercial $defaultCommercial;

    private Product $defaultProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DailySalesInvoicesService::class);

        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);

        $this->defaultCommercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);
        $this->defaultCommercial->team()->associate($team);
        $this->defaultCommercial->save();

        $this->defaultCustomer = Customer::create([
            'name' => 'Client Test',
            'address' => '12 Rue de la Paix',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->defaultCommercial->id,
        ]);

        $this->defaultProduct = Product::create([
            'name' => 'Product '.uniqid(),
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeInvoiceOnDate(Carbon $date, ?SalesInvoiceStatus $status = null): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'customer_id' => $this->defaultCustomer->id,
            'commercial_id' => $this->defaultCommercial->id,
        ]);

        $invoice->created_at = $date->copy()->setTime(12, 0);
        $invoice->save();

        if ($status !== null) {
            $invoice->status = $status;
            $invoice->save();
        }

        return $invoice;
    }

    private function setInvoiceStoredTotals(
        SalesInvoice $invoice,
        int $totalAmount,
        int $totalPayments,
        int $totalEstimatedProfit,
        int $totalRealizedProfit,
        int $estimatedCommercialCommission,
    ): void {
        $invoice->total_amount = $totalAmount;
        $invoice->total_payments = $totalPayments;
        $invoice->total_estimated_profit = $totalEstimatedProfit;
        $invoice->total_realized_profit = $totalRealizedProfit;
        $invoice->estimated_commercial_commission = $estimatedCommercialCommission;
        $invoice->save();
    }

    // =========================================================================
    // getDailySummaries — date filtering
    // =========================================================================

    public function test_returns_only_invoices_for_the_requested_date(): void
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $this->makeInvoiceOnDate($today);
        $this->makeInvoiceOnDate($today);
        $this->makeInvoiceOnDate($yesterday);

        $summaries = $this->service->getDailySales($today, null, null);

        $this->assertCount(2, $summaries);
    }

    public function test_returns_empty_collection_when_no_invoices_on_date(): void
    {
        $summaries = $this->service->getDailySales(Carbon::today(), null, null);

        $this->assertEmpty($summaries);
    }

    // =========================================================================
    // getDailySummaries — commercial_id filter
    // =========================================================================

    public function test_filters_by_commercial_id(): void
    {
        $otherCommercial = Commercial::create([
            'name' => 'Other Commercial',
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);

        $invoiceForDefaultCommercial = $this->makeInvoiceOnDate(Carbon::today());

        $saleInv = SalesInvoice::create([
            'customer_id' => $this->defaultCustomer->id,
            'commercial_id' => $otherCommercial->id,

        ]);
        $saleInv->created_at = Carbon::today()->setTime(12, 0);
        $saleInv->save();

        $summaries = $this->service->getDailySales(Carbon::today(), $this->defaultCommercial->id, null);

        $this->assertCount(1, $summaries);
        $this->assertSame($invoiceForDefaultCommercial->id, $summaries->first()->invoiceId);
    }

    // =========================================================================
    // getDailySummaries — paid_status filter
    // =========================================================================

    public function test_filters_by_paid_status_fully_paid(): void
    {
        $paidInvoice = $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::FullyPaid);
        $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::PartiallyPaid);
        $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::Draft);

        $summaries = $this->service->getDailySales(Carbon::today(), null, 'paid');

        $this->assertCount(1, $summaries);
        $this->assertSame($paidInvoice->id, $summaries->first()->invoiceId);
    }

    public function test_filters_by_paid_status_partial(): void
    {
        $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::FullyPaid);
        $partialInvoice = $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::PartiallyPaid);
        $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::Draft);

        $summaries = $this->service->getDailySales(Carbon::today(), null, 'partial');

        $this->assertCount(1, $summaries);
        $this->assertSame($partialInvoice->id, $summaries->first()->invoiceId);
    }

    public function test_filters_by_paid_status_unpaid_matches_draft_status(): void
    {
        $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::FullyPaid);
        $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::PartiallyPaid);
        $unpaidInvoice = $this->makeInvoiceOnDate(Carbon::today(), SalesInvoiceStatus::Draft);

        $summaries = $this->service->getDailySales(Carbon::today(), null, 'unpaid');

        $this->assertCount(1, $summaries);
        $this->assertSame($unpaidInvoice->id, $summaries->first()->invoiceId);
    }

    // =========================================================================
    // SalesInvoiceDailySummaryDTO — field mapping
    // =========================================================================

    public function test_summary_dto_carries_correct_field_values(): void
    {
        $invoice = $this->makeInvoiceOnDate(Carbon::today());
        $this->setInvoiceStoredTotals(
            invoice: $invoice,
            totalAmount: 10_000,
            totalPayments: 4_000,
            totalEstimatedProfit: 2_000,
            totalRealizedProfit: 800,
            estimatedCommercialCommission: 200,
        );

        $summaries = $this->service->getDailySales(Carbon::today(), null, null);

        $this->assertCount(1, $summaries);
        $dto = $summaries->first();

        $this->assertSame($invoice->id, $dto->invoiceId);
        $this->assertSame($this->defaultCustomer->name, $dto->customerName);
        $this->assertSame($this->defaultCustomer->address, $dto->customerAddress);
        $this->assertSame($this->defaultCommercial->name, $dto->commercialName);
        $this->assertSame(10_000, $dto->totalAmount);
        $this->assertSame(4_000, $dto->totalPayments);
        $this->assertSame(6_000, $dto->totalRemaining); // 10_000 − 4_000
        $this->assertSame(2_000, $dto->totalEstimatedProfit);
        $this->assertSame(800, $dto->totalRealizedProfit);
        $this->assertSame(200, $dto->estimatedCommercialCommission);
        $this->assertSame(0, $dto->deliveryCost);
    }

    public function test_summary_dto_has_null_commercial_name_when_no_commercial(): void
    {
        $invoiceWithNoCommercial = SalesInvoice::create([
            'customer_id' => $this->defaultCustomer->id,
            'commercial_id' => null,
        ]);
        $invoiceWithNoCommercial->created_at = Carbon::today()->setTime(12, 0);
        $invoiceWithNoCommercial->save();

        $summaries = $this->service->getDailySales(Carbon::today(), null, null);

        $this->assertCount(1, $summaries);
        $this->assertNull($summaries->first()->commercialName);
    }

    public function test_summary_dto_to_array_contains_all_expected_keys(): void
    {
        $this->makeInvoiceOnDate(Carbon::today());

        $dto = $this->service->getDailySales(Carbon::today(), null, null)->first();
        $array = $dto->toArray();

        foreach ([
            'invoice_id', 'customer_name', 'customer_address', 'commercial_name',
            'total_amount', 'total_payments', 'total_remaining',
            'total_estimated_profit', 'total_realized_profit',
            'estimated_commercial_commission', 'delivery_cost',
            'status', 'created_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $array, "Missing key: {$key}");
        }
    }

    // =========================================================================
    // SalesInvoicesDailyTotalsDTO — aggregation
    // =========================================================================

    public function test_daily_totals_sums_all_fields_correctly_across_multiple_invoices(): void
    {
        $today = Carbon::today();

        $invoiceA = $this->makeInvoiceOnDate($today);
        $this->setInvoiceStoredTotals($invoiceA, 10_000, 4_000, 2_000, 800, 200);

        $invoiceB = $this->makeInvoiceOnDate($today);
        $this->setInvoiceStoredTotals($invoiceB, 6_000, 6_000, 1_200, 1_200, 120);

        $summaries = $this->service->getDailySales($today, null, null);
        $totals = $this->service->computeDailyTotals($summaries);

        $this->assertSame(2, $totals->invoicesCount);
        $this->assertSame(16_000, $totals->totalAmount);
        $this->assertSame(10_000, $totals->totalPayments);
        $this->assertSame(3_200, $totals->totalEstimatedProfit);
        $this->assertSame(2_000, $totals->totalRealizedProfit);
        $this->assertSame(320, $totals->totalCommissions);
        $this->assertSame(0, $totals->totalDeliveryCost);
    }

    public function test_daily_totals_are_zero_when_no_invoices(): void
    {
        $totals = $this->service->computeDailyTotals(Collection::empty());

        $this->assertSame(0, $totals->invoicesCount);
        $this->assertSame(0, $totals->totalAmount);
        $this->assertSame(0, $totals->totalPayments);
        $this->assertSame(0, $totals->totalCommissions);
        $this->assertSame(0, $totals->totalEstimatedProfit);
        $this->assertSame(0, $totals->totalRealizedProfit);
        $this->assertSame(0, $totals->totalDeliveryCost);
    }

    // =========================================================================
    // SalesInvoicesDailyTotalsDTO::netProfit()
    // =========================================================================

    public function test_net_profit_equals_realized_profit_minus_commissions_minus_delivery_cost(): void
    {
        $totals = new SalesInvoicesDailyTotalsDTO(
            invoicesCount: 3,
            totalAmount: 30_000,
            totalPayments: 20_000,
            totalCommissions: 500,
            totalEstimatedProfit: 6_000,
            totalRealizedProfit: 4_000,
            totalDeliveryCost: 0,
        );

        $this->assertSame(3_500, $totals->netProfit()); // 4_000 − 500 − 0
    }

    public function test_net_profit_is_zero_when_all_components_are_zero(): void
    {
        $totals = new SalesInvoicesDailyTotalsDTO(0, 0, 0, 0, 0, 0, 0);

        $this->assertSame(0, $totals->netProfit());
    }

    public function test_net_profit_can_be_negative_when_commissions_exceed_realized_profit(): void
    {
        $totals = new SalesInvoicesDailyTotalsDTO(
            invoicesCount: 1,
            totalAmount: 1_000,
            totalPayments: 100,
            totalCommissions: 200,
            totalEstimatedProfit: 50,
            totalRealizedProfit: 10,
            totalDeliveryCost: 0,
        );

        $this->assertSame(-190, $totals->netProfit()); // 10 − 200 − 0
    }

    public function test_daily_totals_to_array_includes_net_profit(): void
    {
        $totals = new SalesInvoicesDailyTotalsDTO(
            invoicesCount: 1,
            totalAmount: 5_000,
            totalPayments: 5_000,
            totalCommissions: 100,
            totalEstimatedProfit: 1_000,
            totalRealizedProfit: 1_000,
            totalDeliveryCost: 0,
        );

        $array = $totals->toArray();

        $this->assertArrayHasKey('net_profit', $array);
        $this->assertSame(900, $array['net_profit']); // 1_000 − 100 − 0
    }

    public function test_compute_daily_totals_net_profit_reflects_correct_combined_values(): void
    {
        $today = Carbon::today();

        $invoiceA = $this->makeInvoiceOnDate($today);
        $this->setInvoiceStoredTotals($invoiceA, 5_000, 5_000, 1_000, 1_000, 100);

        $invoiceB = $this->makeInvoiceOnDate($today);
        $this->setInvoiceStoredTotals($invoiceB, 3_000, 1_000, 600, 200, 30);

        $summaries = $this->service->getDailySales($today, null, null);
        $totals = $this->service->computeDailyTotals($summaries);

        // net_profit = (1_000 + 200) − (100 + 30) − 0 = 1_200 − 130 = 1_070
        $this->assertSame(1_070, $totals->netProfit());
    }
}
