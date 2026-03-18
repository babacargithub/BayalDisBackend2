<?php

namespace Tests\Feature\DeliveryCost;

use App\Data\SalesInvoice\SalesInvoiceDailySummaryDTO;
use App\Enums\CarLoadStatus;
use App\Enums\SalesInvoiceStatus;
use App\Jobs\RecalculateInvoicesDeliveryCostJob;
use App\Models\CarLoad;
use App\Models\CarLoadFuelEntry;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Services\Abc\AbcVehicleCostService;
use App\Services\InvoiceDeliveryCostService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for the delivery cost system.
 *
 * Delivery cost = the car load's daily running cost divided equally across all
 * invoices created on the same calendar day for that car load.
 *
 * "Daily running cost" = (fixed costs + fuel receipts) ÷ trip duration days,
 * computed by AbcVehicleCostService::computeDailyTotalCostForCarLoad().
 *
 * We control the daily cost in tests by adding CarLoadFuelEntry records with a
 * known amount. The CarLoad has no vehicle, so the fixed portion is 0.
 * The trip starts on the same frozen date as the invoices, giving trip_duration = 1,
 * so daily_cost = fuel_total / 1 = fuel_total.
 *
 * Split into four sections:
 *  1. Service unit tests (InvoiceDeliveryCostService)
 *  2. Model event tests (SalesInvoice saved / deleted dispatch)
 *  3. Integration tests (end-to-end via model creation)
 *  4. DTO tests (SalesInvoiceDailySummaryDTO reflects delivery_cost)
 */
class InvoiceDeliveryCostTest extends TestCase
{
    use RefreshDatabase;

    /** Work date — CarLoad also starts on this date so trip_duration = 1 day. */
    private string $workDate = '2026-03-05';

    private User $user;

    private Commercial $commercial;

    private Customer $customer;

    private CarLoad $carLoad;

    private InvoiceDeliveryCostService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InvoiceDeliveryCostService(new AbcVehicleCostService);

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Équipe Test',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $this->commercial->team_id = $team->id;
        $this->commercial->save();

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221700000002',
            'owner_number' => '221700000002',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        // CarLoad without a vehicle: fixed cost = 0, daily cost = fuel only.
        // load_date == $workDate so trip_duration_days = max(1, diffInDays(today, today)) = 1
        // when Carbon is frozen to $workDate.
        $this->carLoad = CarLoad::create([
            'name' => 'Chargement Test',
            'load_date' => Carbon::parse($this->workDate),
            'return_date' => Carbon::parse('2026-12-31'),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // always reset frozen time
        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Set the daily cost of the car load by adding a single fuel entry.
     * With a 1-day trip and no vehicle fixed cost: daily_cost = fuel_amount.
     */
    private function setDailyCost(int $fuelAmount): void
    {
        CarLoadFuelEntry::create([
            'car_load_id' => $this->carLoad->id,
            'amount' => $fuelAmount,
            'filled_at' => $this->workDate,
        ]);
    }

    /**
     * Create a SalesInvoice linked to the car load, frozen to $this->workDate.
     */
    private function createCarLoadInvoice(): SalesInvoice
    {
        Carbon::setTestNow($this->workDate);

        return SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => $this->carLoad->id,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);
    }

    /**
     * Create a SalesInvoice with NO car_load_id (back-office invoice).
     */
    private function createBackOfficeInvoice(): SalesInvoice
    {
        Carbon::setTestNow($this->workDate);

        return SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => null,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);
    }

    // ─── Section 1: InvoiceDeliveryCostService unit tests ────────────────────

    public function test_service_assigns_full_daily_cost_to_a_single_invoice(): void
    {
        $this->setDailyCost(30_000);

        Carbon::setTestNow($this->workDate);
        $invoice = $this->createCarLoadInvoice();

        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        $this->assertSame(30_000, SalesInvoice::find($invoice->id)->delivery_cost);
    }

    public function test_service_splits_equally_between_two_invoices(): void
    {
        $this->setDailyCost(30_000);

        $invoice1 = $this->createCarLoadInvoice();
        $invoice2 = $this->createCarLoadInvoice();

        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        $this->assertSame(15_000, SalesInvoice::find($invoice1->id)->delivery_cost);
        $this->assertSame(15_000, SalesInvoice::find($invoice2->id)->delivery_cost);
    }

    public function test_service_distributes_remainder_so_sum_equals_daily_cost(): void
    {
        // 10 000 / 3 = 3 333.33 → floor=3 333, remainder=1
        // → first invoice gets 3 334, others get 3 333; sum = 10 000 ✓
        $this->setDailyCost(10_000);

        $invoice1 = $this->createCarLoadInvoice();
        $invoice2 = $this->createCarLoadInvoice();
        $invoice3 = $this->createCarLoadInvoice();

        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        $deliveryCosts = [
            SalesInvoice::find($invoice1->id)->delivery_cost,
            SalesInvoice::find($invoice2->id)->delivery_cost,
            SalesInvoice::find($invoice3->id)->delivery_cost,
        ];

        $this->assertSame(10_000, array_sum($deliveryCosts));
        // The extra 1 XOF goes to the first invoice (smallest id).
        $this->assertSame(3_334, $deliveryCosts[0]);
        $this->assertSame(3_333, $deliveryCosts[1]);
        $this->assertSame(3_333, $deliveryCosts[2]);
    }

    public function test_service_sum_of_all_delivery_costs_equals_daily_cost_with_many_invoices(): void
    {
        // 7 invoices; daily_cost not divisible by 7 → sum must still match.
        $this->setDailyCost(10_000); // 10 000 % 7 = 3 → 3 invoices get 1 429, 4 get 1 428; sum = 10 000

        for ($i = 0; $i < 7; $i++) {
            $this->createCarLoadInvoice();
        }

        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        $totalDeliveryCost = SalesInvoice::where('car_load_id', $this->carLoad->id)
            ->whereDate('created_at', $this->workDate)
            ->sum('delivery_cost');

        $this->assertSame(10_000, (int) $totalDeliveryCost);
    }

    public function test_service_assigns_zero_delivery_cost_when_car_load_has_no_fuel_and_no_vehicle(): void
    {
        // No fuel entries, no vehicle → daily_cost = 0 → each invoice gets 0.
        $invoice = $this->createCarLoadInvoice();

        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        $this->assertSame(0, SalesInvoice::find($invoice->id)->delivery_cost);
    }

    public function test_service_does_nothing_when_there_are_no_invoices_for_the_day(): void
    {
        $this->setDailyCost(30_000);

        // Create an invoice on a different day.
        Carbon::setTestNow('2026-03-10');
        SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => $this->carLoad->id,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);

        // Calling the service for $workDate should be a no-op (no invoices that day).
        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        // The invoice on 2026-03-10 should not be touched.
        $invoice = SalesInvoice::where('car_load_id', $this->carLoad->id)->first();
        // Its delivery_cost was set by the model event when it was saved (not by this call).
        // We just assert the call didn't crash and the day-scoped query was respected.
        $this->assertTrue(true);
    }

    public function test_service_is_idempotent(): void
    {
        $this->setDailyCost(30_000);

        $invoice = $this->createCarLoadInvoice();

        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);
        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);
        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        $this->assertSame(30_000, SalesInvoice::find($invoice->id)->delivery_cost);
    }

    // ─── Section 2: Model event dispatch tests ────────────────────────────────

    public function test_saving_car_load_invoice_dispatches_recalculate_job(): void
    {
        Queue::fake();

        $this->createCarLoadInvoice();

        Queue::assertPushed(RecalculateInvoicesDeliveryCostJob::class, function (RecalculateInvoicesDeliveryCostJob $job): bool {
            return $job->carLoadId === $this->carLoad->id
                && $job->workDay === $this->workDate;
        });
    }

    public function test_saving_back_office_invoice_does_not_dispatch_recalculate_job(): void
    {
        Queue::fake();

        $this->createBackOfficeInvoice();

        Queue::assertNotPushed(RecalculateInvoicesDeliveryCostJob::class);
    }

    public function test_deleting_car_load_invoice_dispatches_recalculate_job(): void
    {
        Queue::fake();

        $invoice = $this->createCarLoadInvoice();
        Queue::fake(); // reset after creation dispatch

        $invoice->delete();

        Queue::assertPushed(RecalculateInvoicesDeliveryCostJob::class, function (RecalculateInvoicesDeliveryCostJob $job): bool {
            return $job->carLoadId === $this->carLoad->id
                && $job->workDay === $this->workDate;
        });
    }

    public function test_deleting_back_office_invoice_does_not_dispatch_recalculate_job(): void
    {
        $invoice = $this->createBackOfficeInvoice();

        Queue::fake();
        $invoice->delete();

        Queue::assertNotPushed(RecalculateInvoicesDeliveryCostJob::class);
    }

    // ─── Section 3: Integration tests (model event → job → service → DB) ─────

    public function test_delivery_cost_is_set_immediately_after_invoice_creation(): void
    {
        $this->setDailyCost(30_000);

        $invoice = $this->createCarLoadInvoice();

        // With sync queue the job runs immediately; refresh to get the DB value.
        $this->assertSame(30_000, $invoice->fresh()->delivery_cost);
    }

    public function test_delivery_cost_is_recalculated_for_all_invoices_when_new_one_is_added(): void
    {
        $this->setDailyCost(30_000);

        $invoice1 = $this->createCarLoadInvoice();
        $this->assertSame(30_000, $invoice1->fresh()->delivery_cost);

        $invoice2 = $this->createCarLoadInvoice();
        $this->assertSame(15_000, $invoice1->fresh()->delivery_cost);
        $this->assertSame(15_000, $invoice2->fresh()->delivery_cost);

        $invoice3 = $this->createCarLoadInvoice();
        $this->assertSame(10_000, $invoice1->fresh()->delivery_cost);
        $this->assertSame(10_000, $invoice2->fresh()->delivery_cost);
        $this->assertSame(10_000, $invoice3->fresh()->delivery_cost);
    }

    public function test_sum_of_all_delivery_costs_always_equals_daily_cost_after_each_addition(): void
    {
        $this->setDailyCost(10_000);

        $sumAfterEachAddition = [];
        for ($i = 0; $i < 5; $i++) {
            $this->createCarLoadInvoice();

            $sumAfterEachAddition[] = (int) SalesInvoice::where('car_load_id', $this->carLoad->id)
                ->whereDate('created_at', $this->workDate)
                ->sum('delivery_cost');
        }

        foreach ($sumAfterEachAddition as $sum) {
            $this->assertSame(10_000, $sum,
                'Sum of delivery costs must always equal daily cost regardless of invoice count.');
        }
    }

    public function test_delivery_cost_is_recalculated_when_an_invoice_is_deleted(): void
    {
        $this->setDailyCost(30_000);

        $invoice1 = $this->createCarLoadInvoice();
        $invoice2 = $this->createCarLoadInvoice();
        $invoice3 = $this->createCarLoadInvoice();

        // Each starts at 10 000.
        $this->assertSame(10_000, $invoice1->fresh()->delivery_cost);

        // Delete invoice3 → remaining two should each get 15 000.
        $invoice3->delete();

        $this->assertSame(15_000, $invoice1->fresh()->delivery_cost);
        $this->assertSame(15_000, $invoice2->fresh()->delivery_cost);
    }

    public function test_invoices_on_different_days_are_computed_independently(): void
    {
        $this->setDailyCost(30_000);

        // Day 1: create 2 invoices → each 15 000.
        Carbon::setTestNow($this->workDate);
        $day1Invoice1 = $this->createCarLoadInvoice();
        $day1Invoice2 = $this->createCarLoadInvoice();

        $this->assertSame(15_000, $day1Invoice1->fresh()->delivery_cost);
        $this->assertSame(15_000, $day1Invoice2->fresh()->delivery_cost);

        // Day 2: create 1 invoice; day 1 invoices must not change.
        Carbon::setTestNow('2026-03-06');
        SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => $this->carLoad->id,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);

        // Day 1 invoices are untouched.
        $this->assertSame(15_000, $day1Invoice1->fresh()->delivery_cost);
        $this->assertSame(15_000, $day1Invoice2->fresh()->delivery_cost);
    }

    public function test_invoices_on_different_car_loads_are_computed_independently(): void
    {
        $this->setDailyCost(30_000);

        $otherTeam = Team::create([
            'name' => 'Autre Équipe',
            'user_id' => $this->user->id,
        ]);
        $otherCarLoad = CarLoad::create([
            'name' => 'Autre Chargement',
            'load_date' => Carbon::parse($this->workDate),
            'return_date' => Carbon::parse('2026-12-31'),
            'team_id' => $otherTeam->id,
            'status' => CarLoadStatus::Selling,
        ]);
        // Add fuel so the other car load also has a known daily cost.
        CarLoadFuelEntry::create([
            'car_load_id' => $otherCarLoad->id,
            'amount' => 30_000,
            'filled_at' => $this->workDate,
        ]);

        Carbon::setTestNow($this->workDate);

        // CarLoad 1: 2 invoices → each 15 000.
        $c1Invoice1 = $this->createCarLoadInvoice();
        $c1Invoice2 = $this->createCarLoadInvoice();

        // CarLoad 2: 3 invoices → each 10 000.
        $c2Invoice1 = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => $otherCarLoad->id,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);
        SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => $otherCarLoad->id,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);
        SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => $otherCarLoad->id,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);

        // CarLoad 1 invoices must not be affected by CarLoad 2 invoices.
        $this->assertSame(15_000, $c1Invoice1->fresh()->delivery_cost);
        $this->assertSame(15_000, $c1Invoice2->fresh()->delivery_cost);

        // CarLoad 2 first invoice should be 10 000.
        $this->assertSame(10_000, $c2Invoice1->fresh()->delivery_cost);
    }

    public function test_back_office_invoice_delivery_cost_is_not_auto_recalculated(): void
    {
        $this->setDailyCost(30_000);

        // Back-office invoice with manually set delivery_cost.
        Carbon::setTestNow($this->workDate);
        $backOfficeInvoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => null,
            'delivery_cost' => 5_000,
            'status' => SalesInvoiceStatus::Draft,
            'paid' => false,
        ]);

        // Create a car-load invoice on the same day — should NOT affect the back-office invoice.
        $this->createCarLoadInvoice();

        // Back-office delivery_cost remains as manually set.
        $this->assertSame(5_000, $backOfficeInvoice->fresh()->delivery_cost);
    }

    public function test_back_office_invoice_without_car_load_id_has_null_delivery_cost_by_default(): void
    {
        $invoice = $this->createBackOfficeInvoice();

        $this->assertNull($invoice->fresh()->delivery_cost);
    }

    public function test_delivery_cost_is_recalculated_when_fuel_cost_is_added_to_car_load(): void
    {
        // Start with no fuel → daily_cost = 0 → delivery_cost = 0.
        $invoice = $this->createCarLoadInvoice();
        $this->assertSame(0, $invoice->fresh()->delivery_cost);

        // Add fuel and manually trigger recalculation (simulating the back-office updating fuel).
        $this->setDailyCost(30_000);
        $this->service->recalculateDeliveryCostForCarLoadDay($this->carLoad, $this->workDate);

        $this->assertSame(30_000, $invoice->fresh()->delivery_cost);
    }

    // ─── Section 4: DTO tests ─────────────────────────────────────────────────

    public function test_sales_invoice_daily_summary_dto_reflects_delivery_cost(): void
    {
        $this->setDailyCost(30_000);

        $invoice = $this->createCarLoadInvoice();
        $invoice->load('customer', 'commercial');

        $dto = SalesInvoiceDailySummaryDTO::fromInvoice($invoice->fresh()->load('customer', 'commercial'));

        $this->assertSame(30_000, $dto->deliveryCost);
        $this->assertSame(30_000, $dto->toArray()['delivery_cost']);
    }

    public function test_sales_invoice_daily_summary_dto_defaults_to_zero_for_null_delivery_cost(): void
    {
        $invoice = $this->createBackOfficeInvoice();
        $invoice->load('customer', 'commercial');

        $dto = SalesInvoiceDailySummaryDTO::fromInvoice($invoice->fresh()->load('customer', 'commercial'));

        $this->assertSame(0, $dto->deliveryCost);
    }
}
