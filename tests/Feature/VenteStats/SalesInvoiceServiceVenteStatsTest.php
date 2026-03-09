<?php

namespace Tests\Feature\VenteStats;

use App\Data\Vente\PaidStatus;
use App\Data\Vente\VenteStatsFilter;
use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comprehensive tests for SalesInvoiceService::totalSales() and ::totalEstimatedProfits().
 *
 * These are the single source of truth for all sales and profit figures in the application.
 * Every filter, date boundary, paid status, and combination is covered here.
 * A regression in any of these methods means incorrect financial figures are shown to users.
 */
class SalesInvoiceServiceVenteStatsTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceService $salesInvoiceService;

    private Product $defaultProduct;

    private Team $defaultTeam;

    private Commercial $defaultCommercial;

    private Customer $defaultCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesInvoiceService = new SalesInvoiceService;
        $this->defaultProduct = $this->makeProduct();
        $this->defaultTeam = $this->makeTeamWithManager();
        $this->defaultCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $this->defaultCustomer = $this->makeCustomerForCommercial($this->defaultCommercial);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeProduct(int $price = 1000, int $costPrice = 500): Product
    {
        return Product::create([
            'name' => 'Product '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => 1,
        ]);
    }

    private function makeTeamWithManager(): Team
    {
        return Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeCommercialForTeam(Team $team): Commercial
    {
        $commercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();

        return $commercial;
    }

    private function makeCustomerForCommercial(Commercial $commercial): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
        ]);
    }

    private function makeActiveCarLoadForTeam(Team $team): CarLoad
    {
        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' => 'ACTIVE',
            'load_date' => Carbon::now()->subDays(2),
            'return_date' => Carbon::now()->addDays(2),
            'returned' => false,
        ]);
    }

    /**
     * Create a Vente with sensible defaults. Callers override only what matters for the test.
     * commercial_id is routed to the backing SalesInvoice (not the Vente, since the column was removed).
     */
    private function makeVente(array $overrides = []): Vente
    {
        $commercialId = $overrides['commercial_id'] ?? $this->defaultCommercial->id;
        $customerId = ($overrides['customer_id'] ?? null) !== null
            ? $overrides['customer_id']
            : $this->defaultCustomer->id;
        $paid = $overrides['paid'] ?? true;
        $venteType = $overrides['type'] ?? Vente::TYPE_INVOICE;

        unset($overrides['commercial_id']);

        $invoice = SalesInvoice::create([
            'customer_id' => $customerId,
            'commercial_id' => $commercialId,
        ]);

        $venteDefaults = [
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customerId,
            'product_id' => $this->defaultProduct->id,
            'quantity' => 1,
            'price' => 1000,
            'profit' => 300,
            'type' => Vente::TYPE_INVOICE,
        ];

        // SINGLE ventes manage their own paid column directly.
        // INVOICE_ITEM ventes get paid = true via the invoice payment cascade.
        if ($venteType === Vente::TYPE_SINGLE) {
            $venteDefaults['paid'] = $paid;
        }

        $vente = Vente::create(array_merge($venteDefaults, $overrides));

        // For INVOICE_ITEM ventes, create a matching payment and mark the invoice as
        // fully paid so recalculateStoredTotals() propagates paid = true to the vente.
        if ($paid && $venteType === Vente::TYPE_INVOICE) {
            $price = $overrides['price'] ?? 1000;
            $quantity = $overrides['quantity'] ?? 1;
            Payment::create([
                'sales_invoice_id' => $invoice->id,
                'amount' => $price * $quantity,
                'payment_method' => 'Cash',
                'user_id' => User::factory()->create()->id,
            ]);
            $invoice->markAsFullyPaid();
        }

        return $vente->fresh();
    }

    /**
     * Create a SalesInvoice with a single INVOICE_ITEM vente, linked to a specific CarLoad.
     * This is the correct setup for testing carLoadId filter behaviour.
     */
    private function makeInvoiceVenteForCarLoad(int $price, CarLoad $carLoad, ?Commercial $commercial = null, ?Customer $customer = null): Vente
    {
        $commercial ??= $this->defaultCommercial;
        $customer ??= $this->defaultCustomer;

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'paid' => false,
            'car_load_id' => $carLoad->id,
        ]);

        return Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'customer_id' => $customer->id,
            'quantity' => 1,
            'price' => $price,
            'profit' => 0,
            'paid' => false,
            'type' => Vente::TYPE_INVOICE,
        ]);
    }

    /**
     * Create a Vente backdated to a specific date.
     * Uses a second save to override created_at after the initial insert.
     */
    private function makeVenteOnDate(Carbon $date, array $overrides = []): Vente
    {
        $vente = $this->makeVente($overrides);
        $vente->created_at = $date->copy()->startOfDay()->addHours(9); // mid-morning, never on boundary edge
        $vente->save();

        return $vente;
    }

    private function allTimeAllFilter(): VenteStatsFilter
    {
        return new VenteStatsFilter; // PaidStatus::All, all nulls
    }

    // =========================================================================
    // totalSales — base behaviour
    // =========================================================================

    public function test_total_sales_returns_zero_when_no_ventes_exist(): void
    {
        $result = $this->salesInvoiceService->totalSales(null, null, $this->allTimeAllFilter());

        $this->assertSame(0, $result);
    }

    public function test_total_sales_returns_price_times_quantity_for_single_vente(): void
    {
        $this->makeVente(['price' => 2500, 'quantity' => 3]);

        $result = $this->salesInvoiceService->totalSales(null, null, $this->allTimeAllFilter());

        $this->assertSame(7500, $result);
    }

    public function test_total_sales_sums_price_times_quantity_across_multiple_ventes(): void
    {
        $this->makeVente(['price' => 1000, 'quantity' => 2]); // 2 000
        $this->makeVente(['price' => 500,  'quantity' => 3]); // 1 500
        $this->makeVente(['price' => 2000, 'quantity' => 1]); // 2 000

        $result = $this->salesInvoiceService->totalSales(null, null, $this->allTimeAllFilter());

        $this->assertSame(5500, $result);
    }

    public function test_total_sales_always_returns_an_integer(): void
    {
        $this->makeVente(['price' => 1000, 'quantity' => 1]);

        $result = $this->salesInvoiceService->totalSales(null, null, $this->allTimeAllFilter());

        $this->assertIsInt($result);
    }

    public function test_total_sales_handles_large_amounts_without_overflow(): void
    {
        $this->makeVente(['price' => 500000, 'quantity' => 100]); // 50 000 000
        $this->makeVente(['price' => 750000, 'quantity' => 80]);  // 60 000 000

        $result = $this->salesInvoiceService->totalSales(null, null, $this->allTimeAllFilter());

        $this->assertSame(110_000_000, $result);
    }

    // =========================================================================
    // totalSales — PaidStatus filter
    // =========================================================================

    public function test_total_sales_paid_only_filter_excludes_unpaid_ventes(): void
    {
        $this->makeVente(['price' => 1000, 'paid' => true]);
        $this->makeVente(['price' => 2000, 'paid' => false]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_unpaid_only_filter_excludes_paid_ventes(): void
    {
        $this->makeVente(['price' => 1000, 'paid' => true]);
        $this->makeVente(['price' => 2000, 'paid' => false]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::UnpaidOnly)
        );

        $this->assertSame(2000, $result);
    }

    public function test_total_sales_all_filter_includes_both_paid_and_unpaid_ventes(): void
    {
        $this->makeVente(['price' => 1000, 'paid' => true]);
        $this->makeVente(['price' => 2000, 'paid' => false]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            VenteStatsFilter::regardlessOfPaymentStatus()
        );

        $this->assertSame(3000, $result);
    }

    public function test_total_sales_paid_only_returns_zero_when_all_ventes_are_unpaid(): void
    {
        $this->makeVente(['price' => 1500, 'quantity' => 2, 'paid' => false]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly)
        );

        $this->assertSame(0, $result);
    }

    public function test_total_sales_unpaid_only_returns_zero_when_all_ventes_are_paid(): void
    {
        $this->makeVente(['price' => 1500, 'quantity' => 2, 'paid' => true]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::UnpaidOnly)
        );

        $this->assertSame(0, $result);
    }

    // =========================================================================
    // totalSales — date range filters
    // =========================================================================

    public function test_total_sales_start_date_excludes_ventes_created_before_it(): void
    {
        $this->makeVenteOnDate(Carbon::now()->subDays(10), ['price' => 9000]); // before
        $this->makeVenteOnDate(Carbon::now()->subDay(), ['price' => 1000]); // after start → included

        $result = $this->salesInvoiceService->totalSales(
            Carbon::now()->subDays(3), null,
            $this->allTimeAllFilter()
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_end_date_excludes_ventes_created_after_it(): void
    {
        $this->makeVenteOnDate(Carbon::now()->subDay(), ['price' => 1000]); // before end → included
        $this->makeVenteOnDate(Carbon::now()->addDays(5), ['price' => 9000]); // after end

        $result = $this->salesInvoiceService->totalSales(
            null, Carbon::now()->addDays(2),
            $this->allTimeAllFilter()
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_date_range_includes_only_ventes_within_range(): void
    {
        $this->makeVenteOnDate(Carbon::now()->subDays(10), ['price' => 5000]); // before range
        $this->makeVenteOnDate(Carbon::now()->subDays(3), ['price' => 1000, 'quantity' => 2]); // within → 2 000
        $this->makeVenteOnDate(Carbon::now()->subDay(), ['price' => 500,  'quantity' => 3]); // within → 1 500
        $this->makeVenteOnDate(Carbon::now()->addDays(10), ['price' => 8000]); // after range

        $result = $this->salesInvoiceService->totalSales(
            Carbon::now()->subDays(5), Carbon::now(),
            $this->allTimeAllFilter()
        );

        $this->assertSame(3500, $result);
    }

    public function test_total_sales_vente_on_exact_start_date_boundary_is_included(): void
    {
        $startDate = Carbon::now()->subDays(3);
        $this->makeVenteOnDate($startDate, ['price' => 1000]);

        $result = $this->salesInvoiceService->totalSales(
            $startDate, null,
            $this->allTimeAllFilter()
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_vente_on_exact_end_date_boundary_is_included(): void
    {
        $endDate = Carbon::now()->subDay();
        $this->makeVenteOnDate($endDate, ['price' => 1000]);

        $result = $this->salesInvoiceService->totalSales(
            null, $endDate,
            $this->allTimeAllFilter()
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_null_dates_return_all_time_total(): void
    {
        $this->makeVenteOnDate(Carbon::now()->subYears(2), ['price' => 3000]);
        $this->makeVenteOnDate(Carbon::now(), ['price' => 1000]);

        $result = $this->salesInvoiceService->totalSales(null, null, $this->allTimeAllFilter());

        $this->assertSame(4000, $result);
    }

    public function test_total_sales_vente_just_outside_both_date_boundaries_is_excluded(): void
    {
        $startDate = Carbon::now()->subDays(5);
        $endDate = Carbon::now()->subDay();

        $this->makeVenteOnDate($startDate->copy()->subDays(2), ['price' => 999]); // before start
        $this->makeVenteOnDate($endDate->copy()->addDays(2), ['price' => 999]); // after end
        $this->makeVenteOnDate(Carbon::now()->subDays(3), ['price' => 1000]); // within

        $result = $this->salesInvoiceService->totalSales($startDate, $endDate, $this->allTimeAllFilter());

        $this->assertSame(1000, $result);
    }

    // =========================================================================
    // totalSales — commercialId filter
    // =========================================================================

    public function test_total_sales_commercial_id_filter_returns_only_that_commercial_ventes(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $this->makeVente(['price' => 1000, 'commercial_id' => $this->defaultCommercial->id]);
        $this->makeVente(['price' => 5000, 'commercial_id' => $otherCommercial->id, 'customer_id' => $otherCustomer->id]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(commercialId: $this->defaultCommercial->id)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_commercial_id_filter_returns_zero_when_commercial_has_no_ventes(): void
    {
        $commercialWithNoVentes = $this->makeCommercialForTeam($this->defaultTeam);
        $this->makeVente(['price' => 1000]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(commercialId: $commercialWithNoVentes->id)
        );

        $this->assertSame(0, $result);
    }

    public function test_total_sales_commercial_id_filter_sums_multiple_ventes_from_same_commercial(): void
    {
        $this->makeVente(['price' => 1000, 'quantity' => 1]);
        $this->makeVente(['price' => 2000, 'quantity' => 2]); // 4 000

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(commercialId: $this->defaultCommercial->id)
        );

        $this->assertSame(5000, $result);
    }

    // =========================================================================
    // totalSales — customerId filter
    // =========================================================================

    public function test_total_sales_customer_id_filter_returns_only_that_customer_ventes(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $this->makeVente(['price' => 1000, 'customer_id' => $this->defaultCustomer->id]);
        $this->makeVente(['price' => 3000, 'customer_id' => $otherCustomer->id, 'commercial_id' => $otherCommercial->id]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(customerId: $this->defaultCustomer->id)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_customer_id_filter_returns_zero_when_customer_has_no_ventes(): void
    {
        $customerWithNoVentes = $this->makeCustomerForCommercial($this->defaultCommercial);
        $this->makeVente(['price' => 1000]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(customerId: $customerWithNoVentes->id)
        );

        $this->assertSame(0, $result);
    }

    // =========================================================================
    // totalSales — type filter
    // =========================================================================

    public function test_total_sales_type_single_filter_excludes_invoice_item_ventes(): void
    {
        $this->makeVente(['price' => 1000, 'type' => Vente::TYPE_SINGLE]);
        $this->makeVente(['price' => 5000, 'type' => Vente::TYPE_INVOICE, 'customer_id' => null]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(type: Vente::TYPE_SINGLE)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_type_invoice_item_filter_excludes_single_ventes(): void
    {
        $this->makeVente(['price' => 1000, 'type' => Vente::TYPE_SINGLE]);
        $this->makeVente(['price' => 5000, 'type' => Vente::TYPE_INVOICE, 'customer_id' => null]);

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(type: Vente::TYPE_INVOICE)
        );

        $this->assertSame(5000, $result);
    }

    public function test_total_sales_null_type_filter_includes_all_types(): void
    {
        $this->makeVente(['price' => 1000, 'type' => Vente::TYPE_SINGLE]);
        $this->makeVente(['price' => 5000, 'type' => Vente::TYPE_INVOICE, 'customer_id' => null]);

        $result = $this->salesInvoiceService->totalSales(null, null, $this->allTimeAllFilter());

        $this->assertSame(6000, $result);
    }

    // =========================================================================
    // totalSales — carLoadId filter
    // =========================================================================

    public function test_total_sales_car_load_id_filter_scopes_to_invoices_belonging_to_that_car_load(): void
    {
        $targetCarLoad = $this->makeActiveCarLoadForTeam($this->defaultTeam);
        $otherCarLoad = $this->makeActiveCarLoadForTeam($this->makeTeamWithManager());

        $this->makeInvoiceVenteForCarLoad(price: 1000, carLoad: $targetCarLoad);  // ✓ target car load
        $this->makeInvoiceVenteForCarLoad(price: 9000, carLoad: $otherCarLoad);   // ✗ different car load

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(carLoadId: $targetCarLoad->id)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_car_load_id_filter_returns_zero_when_no_invoices_belong_to_that_car_load(): void
    {
        $isolatedCarLoad = $this->makeActiveCarLoadForTeam($this->makeTeamWithManager());

        $this->makeVente(['price' => 1000]); // standalone vente, not linked to any invoice

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(carLoadId: $isolatedCarLoad->id)
        );

        $this->assertSame(0, $result);
    }

    // =========================================================================
    // totalSales — combined filters
    // =========================================================================

    public function test_total_sales_paid_status_and_date_range_both_apply(): void
    {
        $withinRange = Carbon::now()->subDays(2);
        $outsideRange = Carbon::now()->subDays(10);

        $this->makeVenteOnDate($withinRange, ['price' => 1000, 'paid' => true]);  // ✓ matches
        $this->makeVenteOnDate($withinRange, ['price' => 2000, 'paid' => false]); // ✗ unpaid
        $this->makeVenteOnDate($outsideRange, ['price' => 3000, 'paid' => true]);  // ✗ outside range

        $result = $this->salesInvoiceService->totalSales(
            Carbon::now()->subDays(5), Carbon::now(),
            new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_commercial_id_and_paid_status_both_apply(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $this->makeVente(['price' => 1000, 'commercial_id' => $this->defaultCommercial->id, 'paid' => true]);  // ✓
        $this->makeVente(['price' => 2000, 'commercial_id' => $this->defaultCommercial->id, 'paid' => false]); // ✗ unpaid
        $this->makeVente(['price' => 5000, 'commercial_id' => $otherCommercial->id, 'customer_id' => $otherCustomer->id, 'paid' => true]); // ✗ wrong commercial

        $result = $this->salesInvoiceService->totalSales(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly, commercialId: $this->defaultCommercial->id)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_customer_id_and_date_range_both_apply(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $this->makeVenteOnDate(Carbon::now()->subDays(2), ['price' => 1000, 'customer_id' => $this->defaultCustomer->id]); // ✓
        $this->makeVenteOnDate(Carbon::now()->subDays(2), ['price' => 3000, 'customer_id' => $otherCustomer->id, 'commercial_id' => $otherCommercial->id]); // ✗ wrong customer
        $this->makeVenteOnDate(Carbon::now()->subDays(10), ['price' => 1000, 'customer_id' => $this->defaultCustomer->id]); // ✗ outside range

        $result = $this->salesInvoiceService->totalSales(
            Carbon::now()->subDays(5), Carbon::now(),
            new VenteStatsFilter(customerId: $this->defaultCustomer->id)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_sales_all_filters_combined_return_only_exact_match(): void
    {
        $withinRange = Carbon::now()->subDays(2);
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        // Only this one matches all filters
        $this->makeVenteOnDate($withinRange, [
            'price' => 1000,
            'quantity' => 2,
            'paid' => true,
            'type' => Vente::TYPE_INVOICE,
            'commercial_id' => $this->defaultCommercial->id,
            'customer_id' => $this->defaultCustomer->id,
        ]);

        // Wrong paid status
        $this->makeVenteOnDate($withinRange, ['price' => 5000, 'paid' => false]);
        // Wrong commercial
        $this->makeVenteOnDate($withinRange, ['price' => 5000, 'commercial_id' => $otherCommercial->id, 'customer_id' => $otherCustomer->id]);
        // Outside date range
        $this->makeVenteOnDate(Carbon::now()->subDays(20), ['price' => 5000]);

        $result = $this->salesInvoiceService->totalSales(
            Carbon::now()->subDays(5), Carbon::now(),
            new VenteStatsFilter(
                paidStatus: PaidStatus::PaidOnly,
                commercialId: $this->defaultCommercial->id,
                customerId: $this->defaultCustomer->id,
                type: Vente::TYPE_INVOICE,
            )
        );

        $this->assertSame(2000, $result);
    }

    // =========================================================================
    // totalEstimatedProfits — base behaviour
    // =========================================================================

    public function test_total_profits_returns_zero_when_no_ventes_exist(): void
    {
        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertSame(0, $result);
    }

    public function test_total_profits_returns_profit_for_single_vente(): void
    {
        $this->makeVente(['profit' => 450]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertSame(450, $result);
    }

    public function test_total_profits_sums_profit_across_multiple_ventes(): void
    {
        $this->makeVente(['profit' => 300]);
        $this->makeVente(['profit' => 700]);
        $this->makeVente(['profit' => 200]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertSame(1200, $result);
    }

    public function test_total_profits_always_returns_an_integer(): void
    {
        $this->makeVente(['profit' => 500]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertIsInt($result);
    }

    public function test_total_profits_ventes_with_zero_profit_contribute_nothing_to_sum(): void
    {
        $this->makeVente(['profit' => 0]);
        $this->makeVente(['profit' => 0]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertSame(0, $result);
    }

    public function test_total_profits_profit_is_independent_of_price_and_quantity(): void
    {
        // Profit is stored explicitly; it must not be recomputed from price × quantity
        $this->makeVente(['price' => 5000, 'quantity' => 10, 'profit' => 250]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertSame(250, $result);
        $this->assertNotSame(50000, $result); // price × quantity must not be used
    }

    // =========================================================================
    // totalEstimatedProfits — PaidStatus filter
    // =========================================================================

    public function test_total_profits_paid_only_filter_sums_only_paid_vente_profits(): void
    {
        $this->makeVente(['profit' => 300, 'paid' => true]);
        $this->makeVente(['profit' => 700, 'paid' => false]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly)
        );

        $this->assertSame(300, $result);
    }

    public function test_total_profits_unpaid_only_filter_sums_only_unpaid_vente_profits(): void
    {
        $this->makeVente(['profit' => 300, 'paid' => true]);
        $this->makeVente(['profit' => 700, 'paid' => false]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::UnpaidOnly)
        );

        $this->assertSame(700, $result);
    }

    public function test_total_profits_all_filter_sums_both_paid_and_unpaid_profits(): void
    {
        $this->makeVente(['profit' => 300, 'paid' => true]);
        $this->makeVente(['profit' => 700, 'paid' => false]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::All)
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_profits_paid_only_returns_zero_when_no_paid_ventes_exist(): void
    {
        $this->makeVente(['profit' => 400, 'paid' => false]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            null, null,
            new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly)
        );

        $this->assertSame(0, $result);
    }

    // =========================================================================
    // totalEstimatedProfits — date range filters
    // =========================================================================

    public function test_total_profits_date_range_excludes_ventes_outside_range(): void
    {
        $this->makeVenteOnDate(Carbon::now()->subDays(10), ['profit' => 999]); // before
        $this->makeVenteOnDate(Carbon::now()->subDays(2), ['profit' => 400]); // within
        $this->makeVenteOnDate(Carbon::now(), ['profit' => 600]); // within
        $this->makeVenteOnDate(Carbon::now()->addDays(5), ['profit' => 999]); // after

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            Carbon::now()->subDays(5), Carbon::now()->addDay(),
            $this->allTimeAllFilter()
        );

        $this->assertSame(1000, $result);
    }

    public function test_total_profits_null_dates_return_all_time_profit(): void
    {
        $this->makeVenteOnDate(Carbon::now()->subYears(1), ['profit' => 500]);
        $this->makeVenteOnDate(Carbon::now(), ['profit' => 500]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertSame(1000, $result);
    }

    public function test_total_profits_start_date_only_excludes_older_ventes(): void
    {
        $this->makeVenteOnDate(Carbon::now()->subDays(10), ['profit' => 999]); // before start
        $this->makeVenteOnDate(Carbon::now()->subDay(), ['profit' => 400]); // after start

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            Carbon::now()->subDays(3), null,
            $this->allTimeAllFilter()
        );

        $this->assertSame(400, $result);
    }

    // =========================================================================
    // totalEstimatedProfits — commercialId filter
    // =========================================================================

    public function test_total_profits_commercial_id_filter_returns_only_that_commercial_profits(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $this->makeVente(['profit' => 300, 'commercial_id' => $this->defaultCommercial->id]);
        $this->makeVente(['profit' => 700, 'commercial_id' => $otherCommercial->id, 'customer_id' => $otherCustomer->id]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            null, null,
            new VenteStatsFilter(commercialId: $this->defaultCommercial->id)
        );

        $this->assertSame(300, $result);
    }

    // =========================================================================
    // totalEstimatedProfits — customerId filter
    // =========================================================================

    public function test_total_profits_customer_id_filter_returns_only_that_customer_profits(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $this->makeVente(['profit' => 300, 'customer_id' => $this->defaultCustomer->id]);
        $this->makeVente(['profit' => 700, 'customer_id' => $otherCustomer->id, 'commercial_id' => $otherCommercial->id]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            null, null,
            new VenteStatsFilter(customerId: $this->defaultCustomer->id)
        );

        $this->assertSame(300, $result);
    }

    // =========================================================================
    // totalEstimatedProfits — type filter
    // =========================================================================

    public function test_total_profits_type_single_filter_excludes_invoice_item_profits(): void
    {
        $this->makeVente(['profit' => 300, 'type' => Vente::TYPE_SINGLE]);
        $this->makeVente(['profit' => 700, 'type' => Vente::TYPE_INVOICE, 'customer_id' => null]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            null, null,
            new VenteStatsFilter(type: Vente::TYPE_SINGLE)
        );

        $this->assertSame(300, $result);
    }

    public function test_total_profits_null_type_filter_includes_profit_from_all_types(): void
    {
        $this->makeVente(['profit' => 300, 'type' => Vente::TYPE_SINGLE]);
        $this->makeVente(['profit' => 700, 'type' => Vente::TYPE_INVOICE, 'customer_id' => null]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(null, null, $this->allTimeAllFilter());

        $this->assertSame(1000, $result);
    }

    // =========================================================================
    // totalEstimatedProfits — combined filters
    // =========================================================================

    public function test_total_profits_paid_status_commercial_and_date_range_all_apply(): void
    {
        $withinRange = Carbon::now()->subDays(2);
        $outsideRange = Carbon::now()->subDays(15);
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $this->makeVenteOnDate($withinRange, ['profit' => 500, 'paid' => true,  'commercial_id' => $this->defaultCommercial->id]); // ✓
        $this->makeVenteOnDate($withinRange, ['profit' => 999, 'paid' => false, 'commercial_id' => $this->defaultCommercial->id]); // ✗ unpaid
        $this->makeVenteOnDate($withinRange, ['profit' => 999, 'paid' => true,  'commercial_id' => $otherCommercial->id, 'customer_id' => $otherCustomer->id]); // ✗ wrong commercial
        $this->makeVenteOnDate($outsideRange, ['profit' => 999, 'paid' => true, 'commercial_id' => $this->defaultCommercial->id]); // ✗ out of range

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            Carbon::now()->subDays(5), Carbon::now(),
            new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly, commercialId: $this->defaultCommercial->id)
        );

        $this->assertSame(500, $result);
    }

    public function test_total_profits_all_filters_combined_return_only_exact_match(): void
    {
        $withinRange = Carbon::now()->subDays(2);
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        // Only this one matches all criteria
        $this->makeVenteOnDate($withinRange, [
            'profit' => 400,
            'paid' => true,
            'type' => Vente::TYPE_INVOICE,
            'commercial_id' => $this->defaultCommercial->id,
            'customer_id' => $this->defaultCustomer->id,
        ]);

        $this->makeVenteOnDate($withinRange, ['profit' => 999, 'paid' => false]);
        $this->makeVenteOnDate($withinRange, ['profit' => 999, 'commercial_id' => $otherCommercial->id, 'customer_id' => $otherCustomer->id]);
        $this->makeVenteOnDate(Carbon::now()->subDays(20), ['profit' => 999]);

        $result = $this->salesInvoiceService->totalEstimatedProfits(
            Carbon::now()->subDays(5), Carbon::now(),
            new VenteStatsFilter(
                paidStatus: PaidStatus::PaidOnly,
                commercialId: $this->defaultCommercial->id,
                customerId: $this->defaultCustomer->id,
                type: Vente::TYPE_INVOICE,
            )
        );

        $this->assertSame(400, $result);
    }

    // =========================================================================
    // Cross-method integrity: sales and profits are independent computations
    // =========================================================================

    public function test_total_sales_and_total_profits_are_computed_independently(): void
    {
        $this->makeVente(['price' => 2000, 'quantity' => 3, 'profit' => 600]);

        $filter = new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly);
        $sales = $this->salesInvoiceService->totalSales(null, null, $filter);
        $profits = $this->salesInvoiceService->totalEstimatedProfits(null, null, $filter);

        $this->assertSame(6000, $sales);
        $this->assertSame(600, $profits);
    }

    public function test_same_filter_applied_to_both_methods_yields_consistent_results(): void
    {
        // Paid vente: sales 3000, profit 900
        $this->makeVente(['price' => 3000, 'quantity' => 1, 'profit' => 900, 'paid' => true]);
        // Unpaid vente: sales 5000, profit 1500 — must not bleed into PaidOnly results
        $this->makeVente(['price' => 5000, 'quantity' => 1, 'profit' => 1500, 'paid' => false]);

        $filter = new VenteStatsFilter(paidStatus: PaidStatus::PaidOnly);
        $sales = $this->salesInvoiceService->totalSales(null, null, $filter);
        $profits = $this->salesInvoiceService->totalEstimatedProfits(null, null, $filter);

        $this->assertSame(3000, $sales);
        $this->assertSame(900, $profits);
        $this->assertGreaterThan($profits, $sales); // sanity: sales always ≥ profit
    }
}
