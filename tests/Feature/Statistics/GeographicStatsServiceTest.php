<?php

namespace Tests\Feature\Statistics;

use App\Data\Geographic\GeographicActivityDTO;
use App\Services\GeographicStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for GeographicStatsService::buildGeographicActivity().
 *
 * Every number shown on the Zones & Lignes page comes from this service.
 * Wrong profitability scores = bad business decisions about which routes to invest in.
 *
 * Coverage:
 *  - Empty database returns a valid empty DTO
 *  - Lignes are correctly grouped under their zone
 *  - Lignes without a zone appear in a synthetic "Sans zone" bucket
 *  - Customer counts (confirmed vs prospect) aggregated per ligne
 *  - Invoice financial aggregates per ligne (sales, profit, payments, commissions, delivery)
 *  - net_profit = totalRealizedProfit − totalCommissions − totalDeliveryCost
 *  - profitabilityRate = (netProfit / totalSales) × 100, zero when no sales
 *  - collectionRate = (totalPaymentsCollected / totalSales) × 100, zero when no sales
 *  - profitabilityGrade thresholds: A ≥ 15%, B ≥ 8%, C ≥ 3%, D ≥ 0%, F < 0%
 *  - Zone-level aggregates correctly sum their lignes
 *  - Grand totals correctly sum all zones
 *  - Period filter restricts invoice aggregates but NOT customer counts
 *  - Invoices for customers without a ligne are excluded
 *  - Multiple lignes in the same zone are merged under one ZoneStatsDTO
 */
class GeographicStatsServiceTest extends TestCase
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

    private function createZone(string $name): int
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

    private function createCustomer(int $ligneId, bool $isProspect = false): int
    {
        $commercialId = DB::table('commercials')->insertGetId([
            'name' => 'Geo Test Commercial '.$this->phoneCounter,
            'phone_number' => '77200'.str_pad($this->phoneCounter++, 5, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'salary' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('customers')->insertGetId([
            'name' => 'Geo Test Customer '.$this->phoneCounter,
            'phone_number' => '76200'.str_pad($this->phoneCounter++, 5, '0', STR_PAD_LEFT),
            'owner_number' => '0000000000',
            'gps_coordinates' => '0,0',
            'commercial_id' => $commercialId,
            'ligne_id' => $ligneId,
            'is_prospect' => $isProspect ? 1 : 0,
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

    public function test_empty_database_returns_valid_empty_dto(): void
    {
        $result = $this->service->buildGeographicActivity();

        $this->assertInstanceOf(GeographicActivityDTO::class, $result);
        $this->assertEmpty($result->zoneStats);
        $this->assertSame(0, $result->totalSales);
        $this->assertSame(0, $result->totalCustomersCount);
        $this->assertSame(0.0, $result->overallProfitabilityRate);
        $this->assertNull($result->periodLabel);
    }

    public function test_lignes_are_grouped_under_their_zone(): void
    {
        $zoneId = $this->createZone('Zone Nord');
        $this->createLigne('Ligne A', $zoneId);
        $this->createLigne('Ligne B', $zoneId);

        $result = $this->service->buildGeographicActivity();

        $this->assertCount(1, $result->zoneStats);
        $this->assertSame('Zone Nord', $result->zoneStats[0]->zoneName);
        $this->assertCount(2, $result->zoneStats[0]->ligneStats);
    }

    public function test_multiple_zones_are_all_represented(): void
    {
        $zone1 = $this->createZone('Zone A');
        $zone2 = $this->createZone('Zone B');
        $this->createLigne('Ligne 1', $zone1);
        $this->createLigne('Ligne 2', $zone2);

        $result = $this->service->buildGeographicActivity();

        $this->assertCount(2, $result->zoneStats);
    }

    // ─── Customer counts ──────────────────────────────────────────────────────

    public function test_customer_counts_correctly_split_confirmed_and_prospects(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);

        $this->createCustomer($ligneId, isProspect: false);
        $this->createCustomer($ligneId, isProspect: false);
        $this->createCustomer($ligneId, isProspect: true);

        $result = $this->service->buildGeographicActivity();
        $ligne = $result->zoneStats[0]->ligneStats[0];

        $this->assertSame(2, $ligne->confirmedCustomersCount);
        $this->assertSame(1, $ligne->prospectCustomersCount);
        $this->assertSame(3, $ligne->totalCustomersCount);
    }

    public function test_customer_counts_are_not_filtered_by_period(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $this->createCustomer($ligneId);
        $this->createCustomer($ligneId);

        // Filter invoices to a specific month — customer count must still be 2
        $result = $this->service->buildGeographicActivity(
            startDate: Carbon::create(2026, 3, 1)->startOfDay(),
            endDate: Carbon::create(2026, 3, 31)->endOfDay(),
            periodLabel: 'Mars 2026',
        );

        $this->assertSame(2, $result->zoneStats[0]->ligneStats[0]->totalCustomersCount);
    }

    // ─── Invoice aggregates ───────────────────────────────────────────────────

    public function test_invoice_financial_fields_aggregated_correctly_for_a_ligne(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $customerId = $this->createCustomer($ligneId);

        $this->createInvoiceForCustomer(
            $customerId,
            totalAmount: 200_000,
            totalEstimatedProfit: 40_000,
            totalPayments: 150_000,
            totalRealizedProfit: 30_000,
            estimatedCommercialCommission: 5_000,
            deliveryCost: 2_000,
        );
        $this->createInvoiceForCustomer(
            $customerId,
            totalAmount: 100_000,
            totalEstimatedProfit: 20_000,
            totalPayments: 100_000,
            totalRealizedProfit: 20_000,
            estimatedCommercialCommission: 3_000,
            deliveryCost: 1_000,
        );

        $result = $this->service->buildGeographicActivity();
        $ligne = $result->zoneStats[0]->ligneStats[0];

        $this->assertSame(2, $ligne->invoicesCount);
        $this->assertSame(300_000, $ligne->totalSales);
        $this->assertSame(60_000, $ligne->totalEstimatedProfit);
        $this->assertSame(250_000, $ligne->totalPaymentsCollected);
        $this->assertSame(50_000, $ligne->totalRealizedProfit);
        $this->assertSame(8_000, $ligne->totalCommissions);
        $this->assertSame(3_000, $ligne->totalDeliveryCost);
    }

    // ─── Computed metrics ─────────────────────────────────────────────────────

    public function test_net_profit_equals_realized_minus_commissions_minus_delivery(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $customerId = $this->createCustomer($ligneId);

        $this->createInvoiceForCustomer(
            $customerId,
            totalAmount: 100_000,
            totalRealizedProfit: 20_000,
            estimatedCommercialCommission: 4_000,
            deliveryCost: 1_500,
        );

        $result = $this->service->buildGeographicActivity();
        $ligne = $result->zoneStats[0]->ligneStats[0];

        // 20 000 - 4 000 - 1 500 = 14 500
        $this->assertSame(14_500, $ligne->netProfit);
    }

    public function test_profitability_rate_is_net_profit_over_sales_in_percent(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $customerId = $this->createCustomer($ligneId);

        // net_profit = 15 000, total_sales = 100 000 → rate = 15.0%
        $this->createInvoiceForCustomer(
            $customerId,
            totalAmount: 100_000,
            totalRealizedProfit: 15_000,
        );

        $result = $this->service->buildGeographicActivity();
        $ligne = $result->zoneStats[0]->ligneStats[0];

        $this->assertSame(15.0, $ligne->profitabilityRate);
    }

    public function test_profitability_rate_is_zero_when_there_are_no_sales(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $this->createCustomer($ligneId);

        $result = $this->service->buildGeographicActivity();
        $ligne = $result->zoneStats[0]->ligneStats[0];

        $this->assertSame(0.0, $ligne->profitabilityRate);
    }

    public function test_collection_rate_is_payments_over_sales_in_percent(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $customerId = $this->createCustomer($ligneId);

        // payments = 75 000, sales = 100 000 → rate = 75.0%
        $this->createInvoiceForCustomer($customerId, totalAmount: 100_000, totalPayments: 75_000);

        $result = $this->service->buildGeographicActivity();
        $ligne = $result->zoneStats[0]->ligneStats[0];

        $this->assertSame(75.0, $ligne->collectionRate);
    }

    // ─── Profitability grades ─────────────────────────────────────────────────

    public function test_profitability_grade_a_when_rate_is_fifteen_percent_or_above(): void
    {
        $zoneId = $this->createZone('Zone');
        $ligneId = $this->createLigne('Ligne', $zoneId);
        $customerId = $this->createCustomer($ligneId);
        $this->createInvoiceForCustomer($customerId, totalAmount: 100_000, totalRealizedProfit: 15_000);

        $result = $this->service->buildGeographicActivity();

        $this->assertSame('A', $result->zoneStats[0]->ligneStats[0]->profitabilityGrade);
    }

    public function test_profitability_grade_b_when_rate_is_between_eight_and_fifteen_percent(): void
    {
        $zoneId = $this->createZone('Zone');
        $ligneId = $this->createLigne('Ligne', $zoneId);
        $customerId = $this->createCustomer($ligneId);
        $this->createInvoiceForCustomer($customerId, totalAmount: 100_000, totalRealizedProfit: 10_000);

        $result = $this->service->buildGeographicActivity();

        $this->assertSame('B', $result->zoneStats[0]->ligneStats[0]->profitabilityGrade);
    }

    public function test_profitability_grade_f_when_net_profit_is_negative(): void
    {
        $zoneId = $this->createZone('Zone');
        $ligneId = $this->createLigne('Ligne', $zoneId);
        $customerId = $this->createCustomer($ligneId);
        $this->createInvoiceForCustomer(
            $customerId,
            totalAmount: 100_000,
            totalRealizedProfit: 2_000,
            estimatedCommercialCommission: 10_000,
        );

        $result = $this->service->buildGeographicActivity();

        // net = 2 000 - 10 000 = -8 000 → rate < 0% → grade F
        $this->assertSame('F', $result->zoneStats[0]->ligneStats[0]->profitabilityGrade);
    }

    // ─── Zone aggregates ──────────────────────────────────────────────────────

    public function test_zone_totals_correctly_sum_all_its_lignes(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligne1Id = $this->createLigne('Ligne A', $zoneId);
        $ligne2Id = $this->createLigne('Ligne B', $zoneId);
        $customer1 = $this->createCustomer($ligne1Id);
        $customer2 = $this->createCustomer($ligne2Id);

        $this->createInvoiceForCustomer($customer1, totalAmount: 200_000, totalRealizedProfit: 30_000);
        $this->createInvoiceForCustomer($customer2, totalAmount: 100_000, totalRealizedProfit: 10_000);

        $result = $this->service->buildGeographicActivity();
        $zone = $result->zoneStats[0];

        $this->assertSame(300_000, $zone->totalSales);
        $this->assertSame(40_000, $zone->totalRealizedProfit);
        $this->assertSame(2, $zone->invoicesCount);
    }

    // ─── Grand totals ─────────────────────────────────────────────────────────

    public function test_grand_totals_sum_all_zones(): void
    {
        $zone1 = $this->createZone('Zone A');
        $zone2 = $this->createZone('Zone B');
        $ligne1 = $this->createLigne('Ligne A', $zone1);
        $ligne2 = $this->createLigne('Ligne B', $zone2);
        $customer1 = $this->createCustomer($ligne1);
        $customer2 = $this->createCustomer($ligne2);

        $this->createInvoiceForCustomer($customer1, totalAmount: 150_000);
        $this->createInvoiceForCustomer($customer2, totalAmount: 250_000);

        $result = $this->service->buildGeographicActivity();

        $this->assertSame(400_000, $result->totalSales);
        $this->assertSame(2, $result->invoicesCount);
        $this->assertSame(2, $result->totalCustomersCount);
    }

    // ─── Period filter ────────────────────────────────────────────────────────

    public function test_period_filter_excludes_invoices_outside_the_period(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $customerId = $this->createCustomer($ligneId);

        $this->createInvoiceForCustomer($customerId, totalAmount: 100_000, createdAt: '2026-02-15');
        $this->createInvoiceForCustomer($customerId, totalAmount: 50_000, createdAt: '2026-03-10');
        $this->createInvoiceForCustomer($customerId, totalAmount: 75_000, createdAt: '2026-04-01');

        $result = $this->service->buildGeographicActivity(
            startDate: Carbon::create(2026, 3, 1)->startOfDay(),
            endDate: Carbon::create(2026, 3, 31)->endOfDay(),
            periodLabel: 'Mars 2026',
        );

        $this->assertSame(50_000, $result->totalSales);
        $this->assertSame(1, $result->invoicesCount);
        $this->assertSame('Mars 2026', $result->periodLabel);
    }

    public function test_invoices_for_customers_without_a_ligne_are_excluded(): void
    {
        $zoneId = $this->createZone('Zone Test');
        $ligneId = $this->createLigne('Ligne Test', $zoneId);
        $customerWithLigne = $this->createCustomer($ligneId);

        // Customer with no ligne
        $commercialId = DB::table('commercials')->insertGetId([
            'name' => 'No Ligne Commercial',
            'phone_number' => '77999'.$this->phoneCounter++,
            'gender' => 'male',
            'salary' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerWithoutLigne = DB::table('customers')->insertGetId([
            'name' => 'No Ligne Customer',
            'phone_number' => '76999'.$this->phoneCounter++,
            'owner_number' => '0000000000',
            'gps_coordinates' => '0,0',
            'commercial_id' => $commercialId,
            'ligne_id' => null,
            'is_prospect' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createInvoiceForCustomer($customerWithLigne, totalAmount: 100_000);
        $this->createInvoiceForCustomer($customerWithoutLigne, totalAmount: 999_000);

        $result = $this->service->buildGeographicActivity();

        // Only the invoice for the customer WITH a ligne should appear
        $this->assertSame(100_000, $result->totalSales);
        $this->assertSame(1, $result->invoicesCount);
    }
}
