<?php

namespace Tests\Feature\Statistics;

use App\Data\Geographic\LigneSectorSummaryDTO;
use App\Data\Geographic\SectorStatsDTO;
use App\Data\Geographic\TopCustomerDTO;
use App\Services\GeographicStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for GeographicStatsService::buildSectorActivity().
 *
 * The sector drill-down is how management evaluates individual delivery zones.
 * A wrong recurring-customer count or profitability rate means investment decisions
 * are made on bad data — this makes correctness non-negotiable.
 *
 * Coverage:
 *  - Returns a valid LigneSectorSummaryDTO with one SectorStatsDTO per sector
 *  - Empty sectors (no customers, no invoices) are included in the result
 *  - Customer counts (confirmed vs prospect) per sector
 *  - Invoice financial aggregates per sector (sales, profit, payments, commissions)
 *  - Net profit formula: totalRealizedProfit − totalCommissions − totalDeliveryCost
 *  - Profitability rate and grade computed correctly per sector
 *  - Collection rate computed correctly per sector
 *  - Recurring customer count: customers with 2+ invoices in the period
 *  - Recurring customer rate: (recurringCount / totalCustomers) × 100
 *  - Top customers list: ordered by revenue volume, max 5 per sector
 *  - Top customer isRecurring flag set when invoicesCount >= 2
 *  - Ligne totals aggregate correctly across all sectors
 *  - Period filter restricts invoice aggregates but NOT customer counts
 *  - Invoices for customers without a sector assignment are excluded
 *  - Data from another ligne's sector does not bleed into the result
 */
class SectorStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private GeographicStatsService $service;

    private int $phoneCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeographicStatsService;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createZone(string $name = 'Zone Test'): int
    {
        return DB::table('zones')->insertGetId([
            'name' => $name,
            'ville' => $name,
            'gps_coordinates' => '0,0',
            'quartiers' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLigne(string $name, int $zoneId): int
    {
        return DB::table('lignes')->insertGetId([
            'name' => $name,
            'zone_id' => $zoneId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSector(string $name, int $ligneId): int
    {
        return DB::table('sectors')->insertGetId([
            'name' => $name,
            'ligne_id' => $ligneId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCustomer(
        int $ligneId,
        int $sectorId,
        bool $isProspect = false,
        string $name = '',
    ): int {
        $commercialId = DB::table('commercials')->insertGetId([
            'name' => 'Sector Test Commercial '.$this->phoneCounter,
            'phone_number' => '77300'.str_pad($this->phoneCounter++, 5, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'salary' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('customers')->insertGetId([
            'name' => $name ?: ('Sector Test Customer '.$this->phoneCounter),
            'phone_number' => '76300'.str_pad($this->phoneCounter++, 5, '0', STR_PAD_LEFT),
            'owner_number' => '0000000000',
            'gps_coordinates' => '0,0',
            'commercial_id' => $commercialId,
            'ligne_id' => $ligneId,
            'sector_id' => $sectorId,
            'is_prospect' => $isProspect ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCustomerWithoutSector(int $ligneId): int
    {
        $commercialId = DB::table('commercials')->insertGetId([
            'name' => 'No Sector Commercial '.$this->phoneCounter,
            'phone_number' => '77400'.str_pad($this->phoneCounter++, 5, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'salary' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('customers')->insertGetId([
            'name' => 'No Sector Customer '.$this->phoneCounter,
            'phone_number' => '76400'.str_pad($this->phoneCounter++, 5, '0', STR_PAD_LEFT),
            'owner_number' => '0000000000',
            'gps_coordinates' => '0,0',
            'commercial_id' => $commercialId,
            'ligne_id' => $ligneId,
            'sector_id' => null,
            'is_prospect' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createInvoiceForCustomer(
        int $customerId,
        int $totalAmount = 0,
        int $totalEstimatedProfit = 0,
        int $totalPayments = 0,
        int $totalRealizedProfit = 0,
        int $estimatedCommercialCommission = 0,
        int $deliveryCost = 0,
        string $createdAt = '2026-03-15',
    ): int {
        return DB::table('sales_invoices')->insertGetId([
            'customer_id' => $customerId,
            'status' => 'DRAFT',
            'total_amount' => $totalAmount,
            'total_estimated_profit' => $totalEstimatedProfit,
            'total_payments' => $totalPayments,
            'total_realized_profit' => $totalRealizedProfit,
            'estimated_commercial_commission' => $estimatedCommercialCommission,
            'delivery_cost' => $deliveryCost,
            'created_at' => $createdAt.' 10:00:00',
            'updated_at' => $createdAt.' 10:00:00',
        ]);
    }

    // ─── Structure ────────────────────────────────────────────────────────────

    public function test_returns_valid_dto_with_one_sector_stats_entry_per_sector_in_ligne(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $this->createSector('Secteur A', $ligneId);
        $this->createSector('Secteur B', $ligneId);
        $this->createSector('Secteur C', $ligneId);

        $result = $this->service->buildSectorActivity($ligneId);

        $this->assertInstanceOf(LigneSectorSummaryDTO::class, $result);
        $this->assertSame($ligneId, $result->ligneId);
        $this->assertSame('Ligne Alpha', $result->ligneName);
        $this->assertCount(3, $result->sectorStats);

        foreach ($result->sectorStats as $sectorStats) {
            $this->assertInstanceOf(SectorStatsDTO::class, $sectorStats);
        }
    }

    public function test_sectors_are_ordered_alphabetically_by_name(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $this->createSector('Zeta', $ligneId);
        $this->createSector('Alpha', $ligneId);
        $this->createSector('Mida', $ligneId);

        $result = $this->service->buildSectorActivity($ligneId);
        $names = array_map(fn ($s) => $s->sectorName, $result->sectorStats);

        $this->assertSame(['Alpha', 'Mida', 'Zeta'], $names);
    }

    public function test_empty_sector_has_zero_values_and_empty_top_customers_list(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $this->createSector('Secteur Vide', $ligneId);

        $result = $this->service->buildSectorActivity($ligneId);

        $this->assertCount(1, $result->sectorStats);
        $sector = $result->sectorStats[0];

        $this->assertSame(0, $sector->totalCustomersCount);
        $this->assertSame(0, $sector->invoicesCount);
        $this->assertSame(0, $sector->totalSales);
        $this->assertSame(0, $sector->netProfit);
        $this->assertSame(0, $sector->recurringCustomersCount);
        $this->assertSame(0.0, $sector->recurringCustomersRate);
        $this->assertSame(0.0, $sector->profitabilityRate);
        $this->assertSame(0.0, $sector->collectionRate);
        $this->assertEmpty($sector->topCustomers);
    }

    // ─── Customer counts ──────────────────────────────────────────────────────

    public function test_customer_counts_split_confirmed_and_prospect_per_sector(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);

        $this->createCustomer($ligneId, $sectorId, isProspect: false);
        $this->createCustomer($ligneId, $sectorId, isProspect: false);
        $this->createCustomer($ligneId, $sectorId, isProspect: true);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertSame(2, $sector->confirmedCustomersCount);
        $this->assertSame(1, $sector->prospectCustomersCount);
        $this->assertSame(3, $sector->totalCustomersCount);
    }

    public function test_customers_without_sector_assignment_are_excluded_from_counts(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);

        $this->createCustomer($ligneId, $sectorId, isProspect: false);
        $this->createCustomerWithoutSector($ligneId); // must not appear in sector stats

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertSame(1, $sector->totalCustomersCount);
    }

    // ─── Invoice aggregates ───────────────────────────────────────────────────

    public function test_invoice_financial_aggregates_sum_correctly_for_a_sector(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);
        $customerId = $this->createCustomer($ligneId, $sectorId);

        $this->createInvoiceForCustomer($customerId, totalAmount: 10_000, totalEstimatedProfit: 2_000, totalPayments: 6_000, totalRealizedProfit: 1_200, estimatedCommercialCommission: 300, deliveryCost: 200);
        $this->createInvoiceForCustomer($customerId, totalAmount: 5_000, totalEstimatedProfit: 1_000, totalPayments: 5_000, totalRealizedProfit: 1_000, estimatedCommercialCommission: 100, deliveryCost: 50);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertSame(2, $sector->invoicesCount);
        $this->assertSame(15_000, $sector->totalSales);
        $this->assertSame(3_000, $sector->totalEstimatedProfit);
        $this->assertSame(11_000, $sector->totalPaymentsCollected);
        $this->assertSame(2_200, $sector->totalRealizedProfit);
        $this->assertSame(400, $sector->totalCommissions);
        $this->assertSame(250, $sector->totalDeliveryCost);
    }

    public function test_net_profit_equals_realized_profit_minus_commissions_minus_delivery_cost(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);
        $customerId = $this->createCustomer($ligneId, $sectorId);

        $this->createInvoiceForCustomer($customerId, totalRealizedProfit: 5_000, estimatedCommercialCommission: 800, deliveryCost: 200);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        // 5 000 − 800 − 200 = 4 000
        $this->assertSame(4_000, $sector->netProfit);
    }

    public function test_profitability_rate_and_grade_are_computed_correctly(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);
        $customerId = $this->createCustomer($ligneId, $sectorId);

        // net = 4 500 − 500 − 0 = 4 000 ; rate = 4 000 / 20 000 × 100 = 20.0 % → grade A
        $this->createInvoiceForCustomer($customerId, totalAmount: 20_000, totalRealizedProfit: 4_500, estimatedCommercialCommission: 500);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertSame(20.0, $sector->profitabilityRate);
        $this->assertSame('A', $sector->profitabilityGrade);
    }

    public function test_collection_rate_is_computed_correctly(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);
        $customerId = $this->createCustomer($ligneId, $sectorId);

        $this->createInvoiceForCustomer($customerId, totalAmount: 10_000, totalPayments: 7_500);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        // 7 500 / 10 000 × 100 = 75.0 %
        $this->assertSame(75.0, $sector->collectionRate);
    }

    // ─── Recurring customers ──────────────────────────────────────────────────

    public function test_customer_with_single_invoice_is_not_counted_as_recurring(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);
        $customerId = $this->createCustomer($ligneId, $sectorId);

        $this->createInvoiceForCustomer($customerId, totalAmount: 1_000);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertSame(0, $sector->recurringCustomersCount);
        $this->assertSame(0.0, $sector->recurringCustomersRate);
    }

    public function test_customer_with_two_or_more_invoices_is_counted_as_recurring(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);
        $customerA = $this->createCustomer($ligneId, $sectorId); // recurring (3 invoices)
        $customerB = $this->createCustomer($ligneId, $sectorId); // recurring (2 invoices)
        $customerC = $this->createCustomer($ligneId, $sectorId); // not recurring (1 invoice)

        $this->createInvoiceForCustomer($customerA, totalAmount: 1_000);
        $this->createInvoiceForCustomer($customerA, totalAmount: 1_000);
        $this->createInvoiceForCustomer($customerA, totalAmount: 1_000);
        $this->createInvoiceForCustomer($customerB, totalAmount: 1_000);
        $this->createInvoiceForCustomer($customerB, totalAmount: 1_000);
        $this->createInvoiceForCustomer($customerC, totalAmount: 1_000);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        // 3 total customers, 2 are recurring → rate = 66.7 %
        $this->assertSame(2, $sector->recurringCustomersCount);
        $this->assertSame(66.7, $sector->recurringCustomersRate);
    }

    // ─── Top customers ────────────────────────────────────────────────────────

    public function test_top_customers_are_ordered_by_revenue_volume_descending(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);

        $lowCustomerId = $this->createCustomer($ligneId, $sectorId, name: 'Low Spender');
        $highCustomerId = $this->createCustomer($ligneId, $sectorId, name: 'High Spender');
        $midCustomerId = $this->createCustomer($ligneId, $sectorId, name: 'Mid Spender');

        $this->createInvoiceForCustomer($lowCustomerId, totalAmount: 1_000);
        $this->createInvoiceForCustomer($highCustomerId, totalAmount: 9_000);
        $this->createInvoiceForCustomer($midCustomerId, totalAmount: 4_000);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertCount(3, $sector->topCustomers);
        $this->assertInstanceOf(TopCustomerDTO::class, $sector->topCustomers[0]);
        $this->assertSame(9_000, $sector->topCustomers[0]->totalSales);
        $this->assertSame(4_000, $sector->topCustomers[1]->totalSales);
        $this->assertSame(1_000, $sector->topCustomers[2]->totalSales);
    }

    public function test_top_customers_list_is_capped_at_five_per_sector(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);

        for ($i = 0; $i < 8; $i++) {
            $customerId = $this->createCustomer($ligneId, $sectorId);
            $this->createInvoiceForCustomer($customerId, totalAmount: ($i + 1) * 1_000);
        }

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertCount(5, $sector->topCustomers);
    }

    public function test_top_customer_is_recurring_flag_is_true_when_invoice_count_is_two_or_more(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);

        $recurringCustomerId = $this->createCustomer($ligneId, $sectorId, name: 'Recurring');
        $singleOrderCustomerId = $this->createCustomer($ligneId, $sectorId, name: 'Single');

        $this->createInvoiceForCustomer($recurringCustomerId, totalAmount: 5_000);
        $this->createInvoiceForCustomer($recurringCustomerId, totalAmount: 5_000); // 2 invoices → recurring
        $this->createInvoiceForCustomer($singleOrderCustomerId, totalAmount: 1_000);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        // Top by volume: recurring first (10 000), then single (1 000)
        $this->assertTrue($sector->topCustomers[0]->isRecurring);
        $this->assertFalse($sector->topCustomers[1]->isRecurring);
    }

    // ─── Period filter ────────────────────────────────────────────────────────

    public function test_period_filter_excludes_invoices_outside_the_date_range(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);
        $customerId = $this->createCustomer($ligneId, $sectorId);

        $this->createInvoiceForCustomer($customerId, totalAmount: 10_000, createdAt: '2026-03-10'); // inside
        $this->createInvoiceForCustomer($customerId, totalAmount: 5_000, createdAt: '2026-02-28');  // outside

        $start = Carbon::parse('2026-03-01')->startOfDay();
        $end = Carbon::parse('2026-03-31')->endOfDay();

        $result = $this->service->buildSectorActivity($ligneId, $start, $end);
        $sector = $result->sectorStats[0];

        $this->assertSame(1, $sector->invoicesCount);
        $this->assertSame(10_000, $sector->totalSales);
    }

    public function test_period_filter_does_not_affect_customer_counts(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorId = $this->createSector('Secteur A', $ligneId);

        $this->createCustomer($ligneId, $sectorId, isProspect: false);
        $this->createCustomer($ligneId, $sectorId, isProspect: true);

        // Future month with no invoices
        $start = Carbon::parse('2026-06-01')->startOfDay();
        $end = Carbon::parse('2026-06-30')->endOfDay();

        $result = $this->service->buildSectorActivity($ligneId, $start, $end);
        $sector = $result->sectorStats[0];

        // Customers still counted even though the period has no invoices
        $this->assertSame(2, $sector->totalCustomersCount);
        $this->assertSame(0, $sector->invoicesCount);
    }

    // ─── Cross-sector isolation ───────────────────────────────────────────────

    public function test_invoices_for_customers_without_a_sector_are_excluded(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $this->createSector('Secteur A', $ligneId);
        $customerWithoutSector = $this->createCustomerWithoutSector($ligneId);

        $this->createInvoiceForCustomer($customerWithoutSector, totalAmount: 99_000);

        $result = $this->service->buildSectorActivity($ligneId);
        $sector = $result->sectorStats[0];

        $this->assertSame(0, $sector->invoicesCount);
        $this->assertSame(0, $sector->totalSales);
    }

    public function test_data_from_another_lignes_sector_does_not_bleed_into_the_result(): void
    {
        $zoneId = $this->createZone();
        $ligneA = $this->createLigne('Ligne A', $zoneId);
        $ligneB = $this->createLigne('Ligne B', $zoneId);
        $sectorA = $this->createSector('Secteur A', $ligneA);
        $sectorB = $this->createSector('Secteur B', $ligneB);

        $customerInA = $this->createCustomer($ligneA, $sectorA);
        $customerInB = $this->createCustomer($ligneB, $sectorB);

        $this->createInvoiceForCustomer($customerInA, totalAmount: 5_000);
        $this->createInvoiceForCustomer($customerInB, totalAmount: 99_000); // must not appear in ligne A

        $result = $this->service->buildSectorActivity($ligneA);

        $this->assertCount(1, $result->sectorStats); // only Secteur A
        $this->assertSame(5_000, $result->sectorStats[0]->totalSales);
    }

    // ─── Ligne totals ──────────────────────────────────────────────────────────

    public function test_ligne_totals_aggregate_all_sectors_correctly(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorA = $this->createSector('Secteur A', $ligneId);
        $sectorB = $this->createSector('Secteur B', $ligneId);

        $customerA = $this->createCustomer($ligneId, $sectorA);
        $customerB = $this->createCustomer($ligneId, $sectorB);

        $this->createInvoiceForCustomer($customerA, totalAmount: 8_000, totalRealizedProfit: 1_600, estimatedCommercialCommission: 200, deliveryCost: 100);
        $this->createInvoiceForCustomer($customerB, totalAmount: 12_000, totalRealizedProfit: 2_400, estimatedCommercialCommission: 300, deliveryCost: 150);

        $result = $this->service->buildSectorActivity($ligneId);

        $this->assertSame(20_000, $result->totalSales);
        // net = (1600 + 2400) − (200 + 300) − (100 + 150) = 4000 − 500 − 250 = 3250
        $this->assertSame(3_250, $result->netProfit);
        // profitabilityRate = 3250 / 20000 × 100 = 16.25 % → rounded to 16.3 %
        $this->assertSame(16.3, $result->overallProfitabilityRate);
    }

    public function test_recurring_customers_total_sums_across_all_sectors(): void
    {
        $zoneId = $this->createZone();
        $ligneId = $this->createLigne('Ligne Alpha', $zoneId);
        $sectorA = $this->createSector('Secteur A', $ligneId);
        $sectorB = $this->createSector('Secteur B', $ligneId);

        // 1 recurring customer in sector A
        $customerA = $this->createCustomer($ligneId, $sectorA);
        $this->createInvoiceForCustomer($customerA, totalAmount: 1_000);
        $this->createInvoiceForCustomer($customerA, totalAmount: 1_000);

        // 1 recurring customer in sector B
        $customerB = $this->createCustomer($ligneId, $sectorB);
        $this->createInvoiceForCustomer($customerB, totalAmount: 1_000);
        $this->createInvoiceForCustomer($customerB, totalAmount: 1_000);

        $result = $this->service->buildSectorActivity($ligneId);

        $this->assertSame(2, $result->recurringCustomersCount);
    }
}
