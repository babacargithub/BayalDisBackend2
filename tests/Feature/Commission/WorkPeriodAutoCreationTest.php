<?php

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use App\Services\Commission\DailyCommissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that a CommercialWorkPeriod is automatically created (Monday to Sunday
 * of the relevant week) when no period exists at the time of:
 *   - a new SalesInvoice being saved
 *   - a new Payment being processed through DailyCommissionService
 *
 * Also verifies that an existing covering period is never duplicated, that wider
 * manually-configured periods are respected, and that finalized periods block
 * commission recalculation without creating a replacement period.
 */
class WorkPeriodAutoCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->commercial = Commercial::create([
            'name' => 'Commercial Auto-Period',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Produit Test',
            'price' => 5_000,
            'cost_price' => 3_000,
        ]);
    }

    // =========================================================================
    // findOrCreateWeeklyPeriodForCommercialOnDate — unit-level
    // =========================================================================

    public function test_creates_monday_to_sunday_period_for_a_midweek_date(): void
    {
        CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $this->commercial->id,
            date: '2026-03-18', // Wednesday
        );

        $this->assertDatabaseCount('commercial_work_periods', 1);
        $workPeriod = CommercialWorkPeriod::first();
        $this->assertEquals('2026-03-16', $workPeriod->period_start_date->toDateString());
        $this->assertEquals('2026-03-22', $workPeriod->period_end_date->toDateString());
        $this->assertFalse($workPeriod->is_finalized);
    }

    public function test_creates_period_starting_on_monday_when_date_is_a_monday(): void
    {
        CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $this->commercial->id,
            date: '2026-03-16', // Monday
        );

        $workPeriod = CommercialWorkPeriod::first();
        $this->assertEquals('2026-03-16', $workPeriod->period_start_date->toDateString());
        $this->assertEquals('2026-03-22', $workPeriod->period_end_date->toDateString());
    }

    public function test_creates_period_ending_on_sunday_when_date_is_a_sunday(): void
    {
        CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $this->commercial->id,
            date: '2026-03-22', // Sunday
        );

        $workPeriod = CommercialWorkPeriod::first();
        $this->assertEquals('2026-03-16', $workPeriod->period_start_date->toDateString());
        $this->assertEquals('2026-03-22', $workPeriod->period_end_date->toDateString());
    }

    public function test_returns_existing_period_without_creating_a_duplicate(): void
    {
        $existingPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-16',
            'period_end_date' => '2026-03-22',
        ]);

        $returned = CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $this->commercial->id,
            date: '2026-03-18',
        );

        $this->assertDatabaseCount('commercial_work_periods', 1);
        $this->assertEquals($existingPeriod->id, $returned->id);
    }

    public function test_does_not_replace_a_wider_manually_configured_period(): void
    {
        // Bi-weekly period that already covers the date
        CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-09',
            'period_end_date' => '2026-03-22',
        ]);

        CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $this->commercial->id,
            date: '2026-03-18',
        );

        $this->assertDatabaseCount('commercial_work_periods', 1);
        $this->assertEquals('2026-03-09', CommercialWorkPeriod::first()->period_start_date->toDateString());
    }

    // =========================================================================
    // SalesInvoice::saved hook
    // =========================================================================

    public function test_saving_a_sales_invoice_creates_a_weekly_work_period_when_none_exists(): void
    {
        SalesInvoice::create([
            'customer_id' => $this->makeCustomer()->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        $this->assertDatabaseCount('commercial_work_periods', 1);

        $workPeriod = CommercialWorkPeriod::first();
        $this->assertEquals($this->commercial->id, $workPeriod->commercial_id);
        $this->assertFalse($workPeriod->is_finalized);
    }

    public function test_saving_a_sales_invoice_does_not_duplicate_an_existing_covering_period(): void
    {
        Carbon::setTestNow('2026-03-18 10:00:00');

        CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-16',
            'period_end_date' => '2026-03-22',
        ]);

        SalesInvoice::create([
            'customer_id' => $this->makeCustomer()->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        Carbon::setTestNow();

        $this->assertDatabaseCount('commercial_work_periods', 1);
    }

    public function test_saving_a_sales_invoice_without_a_commercial_does_not_create_a_period(): void
    {
        SalesInvoice::create([
            'customer_id' => $this->makeCustomer()->id,
            'commercial_id' => null,
            'status' => 'DRAFT',
        ]);

        $this->assertDatabaseCount('commercial_work_periods', 0);
    }

    // =========================================================================
    // DailyCommissionService::recalculateDailyCommissionForPaymentData
    // =========================================================================

    public function test_processing_a_payment_creates_a_weekly_work_period_when_none_exists(): void
    {
        $product = $this->makeProduct();
        $invoice = SalesInvoice::create([
            'customer_id' => $this->makeCustomer()->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 5_000,
            'profit' => 4_000,
            'type' => Vente::TYPE_INVOICE,
        ]);

        // Remove any period that was auto-created by the invoice saved event.
        CommercialWorkPeriod::query()->delete();
        $this->assertDatabaseCount('commercial_work_periods', 0);

        app(DailyCommissionService::class)->recalculateDailyCommissionForPaymentData(
            userId: $this->user->id,
            workDay: '2026-03-18',
            salesInvoiceId: $invoice->id,
        );

        $this->assertDatabaseCount('commercial_work_periods', 1);
        $workPeriod = CommercialWorkPeriod::first();
        $this->assertEquals('2026-03-16', $workPeriod->period_start_date->toDateString());
        $this->assertEquals('2026-03-22', $workPeriod->period_end_date->toDateString());

        // A DailyCommission record must also have been created for the work day.
        $this->assertDatabaseCount('daily_commissions', 1);
        $this->assertEquals('2026-03-18', DailyCommission::first()->work_day->toDateString());
    }

    public function test_processing_a_payment_does_not_duplicate_an_existing_covering_period(): void
    {
        Carbon::setTestNow('2026-03-18 10:00:00');

        $existingPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-16',
            'period_end_date' => '2026-03-22',
        ]);

        $invoice = SalesInvoice::create([
            'customer_id' => $this->makeCustomer()->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        Carbon::setTestNow();

        app(DailyCommissionService::class)->recalculateDailyCommissionForPaymentData(
            userId: $this->user->id,
            workDay: '2026-03-18',
            salesInvoiceId: $invoice->id,
        );

        $this->assertDatabaseCount('commercial_work_periods', 1);
        $this->assertEquals($existingPeriod->id, CommercialWorkPeriod::first()->id);
    }

    public function test_a_finalized_covering_period_blocks_commission_recalculation_without_creating_a_replacement(): void
    {
        CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-16',
            'period_end_date' => '2026-03-22',
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);

        app(DailyCommissionService::class)->recalculateDailyCommissionForPaymentData(
            userId: $this->user->id,
            workDay: '2026-03-18',
            salesInvoiceId: 999,
        );

        // Finalized period is found but no replacement is created.
        $this->assertDatabaseCount('commercial_work_periods', 1);
        // Commission recalculation was skipped — no daily commission record.
        $this->assertDatabaseCount('daily_commissions', 0);
    }
}
