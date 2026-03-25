<?php

namespace Tests\Feature\Refactoring;

use App\Enums\SalesInvoiceStatus;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the bug where invoices migrated from TYPE_SINGLE ventes
 * kept car_load_id = NULL after running bayal:link-invoices-to-car-loads.
 *
 * Root cause: MigrateSingleVentesToInvoices hardcoded commercial_id = null on
 * every invoice it created, and LinkInvoicesToCarLoads had a
 * ->whereNotNull('commercial_id') filter that permanently skipped those rows.
 *
 * Fix:
 *  1. MigrateSingleVentesToInvoices now resolves commercial_id from customers.commercial_id.
 *  2. LinkInvoicesToCarLoads now falls back to customers.commercial_id when
 *     invoice.commercial_id is null.
 */
class LinkInvoicesToCarLoadsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Commercial $commercial;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Equipe Test',
            'user_id' => $user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $user->id,
        ]);

        // team_id is not in Commercial::$fillable — assign it directly.
        DB::table('commercials')
            ->where('id', $this->commercial->id)
            ->update(['team_id' => $this->team->id]);

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'address' => 'Dakar',
            'phone_number' => '221700000002',
            'owner_number' => '221700000002',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Inserts a car load for the test team via raw DB to bypass model events.
     */
    private function makeCarLoad(string $loadDate, string $returnDate): int
    {
        return DB::table('car_loads')->insertGetId([
            'name' => 'Chargement Test',
            'load_date' => $loadDate,
            'return_date' => $returnDate,
            'status' => 'TERMINATED_AND_TRANSFERRED',
            'returned' => true,
            'team_id' => $this->team->id,
            'vehicle_id' => null,
            'fixed_daily_cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Inserts a sales invoice with commercial_id = NULL (simulating what
     * MigrateSingleVentesToInvoices produced before the fix).
     */
    private function makeInvoiceWithNullCommercialId(string $createdAt): int
    {
        return DB::table('sales_invoices')->insertGetId([
            'invoice_number' => 'HIST-'.uniqid(),
            'customer_id' => $this->customer->id,
            'commercial_id' => null,
            'car_load_id' => null,
            'status' => SalesInvoiceStatus::Draft->value,
            'paid' => false,
            'total_amount' => 0,
            'total_payments' => 0,
            'total_estimated_profit' => 0,
            'total_realized_profit' => 0,
            'created_at' => $createdAt,
            'updated_at' => now(),
        ]);
    }

    /**
     * Inserts a sales invoice with commercial_id set (normal invoice).
     */
    private function makeInvoiceWithCommercialId(string $createdAt): int
    {
        return DB::table('sales_invoices')->insertGetId([
            'invoice_number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'car_load_id' => null,
            'status' => SalesInvoiceStatus::Draft->value,
            'paid' => false,
            'total_amount' => 0,
            'total_payments' => 0,
            'total_estimated_profit' => 0,
            'total_realized_profit' => 0,
            'created_at' => $createdAt,
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_invoice_with_null_commercial_id_gets_linked_via_customer_commercial(): void
    {
        // Car load window: 2025-03-01 to 2025-03-31
        $carLoadId = $this->makeCarLoad('2025-03-01 00:00:00', '2025-03-31 23:59:59');

        // Invoice created within the car load window, but commercial_id = NULL
        $invoiceId = $this->makeInvoiceWithNullCommercialId('2025-03-15 10:00:00');

        Artisan::call('bayal:link-invoices-to-car-loads');

        $linkedCarLoadId = DB::table('sales_invoices')->where('id', $invoiceId)->value('car_load_id');

        $this->assertSame($carLoadId, $linkedCarLoadId, 'Invoice with null commercial_id should be linked via customer.commercial_id');
    }

    public function test_invoice_with_commercial_id_still_gets_linked_normally(): void
    {
        $carLoadId = $this->makeCarLoad('2025-03-01 00:00:00', '2025-03-31 23:59:59');

        $invoiceId = $this->makeInvoiceWithCommercialId('2025-03-20 10:00:00');

        Artisan::call('bayal:link-invoices-to-car-loads');

        $linkedCarLoadId = DB::table('sales_invoices')->where('id', $invoiceId)->value('car_load_id');

        $this->assertSame($carLoadId, $linkedCarLoadId);
    }

    public function test_invoice_with_null_commercial_id_uses_gap_fallback_when_outside_car_load_window(): void
    {
        // Car load ended on the 15th; invoice was created on the 20th (gap).
        $carLoadId = $this->makeCarLoad('2025-03-01 00:00:00', '2025-03-15 23:59:59');

        $invoiceId = $this->makeInvoiceWithNullCommercialId('2025-03-20 10:00:00');

        Artisan::call('bayal:link-invoices-to-car-loads');

        $linkedCarLoadId = DB::table('sales_invoices')->where('id', $invoiceId)->value('car_load_id');

        $this->assertSame(
            $carLoadId,
            $linkedCarLoadId,
            'Invoice in a gap after car load end should be assigned via fallback, even with null commercial_id'
        );
    }

    public function test_invoice_that_predates_all_car_loads_gets_linked_to_earliest_car_load(): void
    {
        // Car load starts March 1 — invoice was created January 15 (before any car load).
        $carLoadId = $this->makeCarLoad('2025-03-01 00:00:00', '2025-03-31 23:59:59');

        $invoiceId = $this->makeInvoiceWithCommercialId('2025-01-15 10:00:00');

        Artisan::call('bayal:link-invoices-to-car-loads');

        $linkedCarLoadId = DB::table('sales_invoices')->where('id', $invoiceId)->value('car_load_id');

        $this->assertSame(
            $carLoadId,
            $linkedCarLoadId,
            'Invoice that predates all car loads should be assigned to the earliest car load (pre-history fallback)'
        );
    }

    public function test_invoice_with_null_commercial_id_whose_customer_commercial_has_no_team_stays_unlinked(): void
    {
        $this->makeCarLoad('2025-03-01 00:00:00', '2025-03-31 23:59:59');

        // A commercial with no team — cannot be linked to any car load.
        $commercialWithNoTeamId = DB::table('commercials')->insertGetId([
            'name' => 'Commercial sans equipe',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A customer assigned to that team-less commercial.
        $customerWithTeamlessCommercialId = DB::table('customers')->insertGetId([
            'name' => 'Client sans equipe',
            'address' => 'Dakar',
            'phone_number' => '221700000099',
            'owner_number' => '221700000099',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercialWithNoTeamId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Invoice with commercial_id = NULL; customer's commercial has no team.
        $invoiceId = $this->makeInvoiceWithNullCommercialId('2025-03-15 10:00:00');

        DB::table('sales_invoices')
            ->where('id', $invoiceId)
            ->update(['customer_id' => $customerWithTeamlessCommercialId]);

        Artisan::call('bayal:link-invoices-to-car-loads');

        $linkedCarLoadId = DB::table('sales_invoices')->where('id', $invoiceId)->value('car_load_id');

        $this->assertNull($linkedCarLoadId, 'Invoice whose customer commercial has no team cannot be linked to a car load');
    }

    public function test_migrate_single_ventes_to_invoices_sets_commercial_id_from_customer(): void
    {
        // Insert a TYPE_SINGLE vente directly — simulating legacy mobile app data.
        $productId = DB::table('products')->insertGetId([
            'name' => 'Produit Test',
            'price' => 500,
            'cost_price' => 300,
            'base_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ventes')->insert([
            'product_id' => $productId,
            'customer_id' => $this->customer->id,
            'sales_invoice_id' => null,
            'type' => 'SINGLE',
            'quantity' => 2,
            'price' => 500,
            'profit' => 400,
            'paid' => true,
            'payment_method' => 'CASH',
            'created_at' => '2025-03-15 10:00:00',
            'updated_at' => now(),
        ]);

        Artisan::call('bayal:migrate-single-ventes-to-invoices');

        $createdInvoice = DB::table('sales_invoices')
            ->where('customer_id', $this->customer->id)
            ->first();

        $this->assertNotNull($createdInvoice, 'Migration should have created an invoice');
        $this->assertSame(
            $this->commercial->id,
            (int) $createdInvoice->commercial_id,
            'Migrated invoice must carry the commercial_id from the customer record'
        );
    }
}
