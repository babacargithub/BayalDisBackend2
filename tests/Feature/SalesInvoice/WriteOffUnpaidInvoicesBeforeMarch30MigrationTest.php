<?php

namespace Tests\Feature\SalesInvoice;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration must write off only invoices that are unpaid AND created strictly
 * before 2026-03-30 00:00, and must be safe to run more than once.
 */
class WriteOffUnpaidInvoicesBeforeMarch30MigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_10_10_125200_write_off_unpaid_invoices_created_before_march_30_2026.php';

    private Customer $customer;

    private Commercial $commercial;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $team->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        $this->product = Product::create([
            'name' => 'Product '.uniqid(),
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);
    }

    private function makeInvoiceCreatedAt(string $createdAt, int $price = 5000): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'price' => $price,
            'quantity' => 1,
            'profit' => 400,
            'type' => Vente::TYPE_INVOICE,
        ]);

        SalesInvoice::withoutGlobalScopes()
            ->whereKey($invoice->id)
            ->update(['created_at' => Carbon::parse($createdAt)]);

        return $invoice->fresh();
    }

    private function runMigration(): void
    {
        /** @var Migration $migration */
        $migration = require base_path(self::MIGRATION_PATH);
        $migration->up();
    }

    private function isWrittenOff(SalesInvoice $invoice): bool
    {
        return SalesInvoice::query()->withWrittenOff()->findOrFail($invoice->id)->written_off_at !== null;
    }

    public function test_unpaid_invoices_before_cutoff_are_written_off(): void
    {
        $firstOldInvoice = $this->makeInvoiceCreatedAt('2026-01-15 10:00:00');
        $secondOldInvoice = $this->makeInvoiceCreatedAt('2026-03-01 08:00:00', 12000);
        $thirdOldInvoice = $this->makeInvoiceCreatedAt('2025-11-20 17:30:00', 700);

        $this->runMigration();

        $this->assertTrue($this->isWrittenOff($firstOldInvoice));
        $this->assertTrue($this->isWrittenOff($secondOldInvoice));
        $this->assertTrue($this->isWrittenOff($thirdOldInvoice));
        $this->assertSame(0, SalesInvoice::query()->count());
    }

    public function test_invoice_created_last_second_before_cutoff_is_written_off(): void
    {
        $invoice = $this->makeInvoiceCreatedAt('2026-03-29 23:59:59');

        $this->runMigration();

        $this->assertTrue($this->isWrittenOff($invoice));
    }

    public function test_invoice_created_exactly_at_cutoff_is_not_written_off(): void
    {
        $invoice = $this->makeInvoiceCreatedAt('2026-03-30 00:00:00');

        $this->runMigration();

        $this->assertFalse($this->isWrittenOff($invoice));
    }

    public function test_invoices_created_after_cutoff_are_not_written_off(): void
    {
        $dayOfCutoffInvoice = $this->makeInvoiceCreatedAt('2026-03-30 14:00:00');
        $recentInvoice = $this->makeInvoiceCreatedAt('2026-09-01 09:00:00');

        $this->runMigration();

        $this->assertFalse($this->isWrittenOff($dayOfCutoffInvoice));
        $this->assertFalse($this->isWrittenOff($recentInvoice));
    }

    public function test_partially_paid_invoice_before_cutoff_is_left_untouched(): void
    {
        $partiallyPaidOldInvoice = $this->makeInvoiceCreatedAt('2026-02-10 10:00:00', 5000);

        Payment::create([
            'sales_invoice_id' => $partiallyPaidOldInvoice->id,
            'amount' => 1000,
            'payment_method' => 'CASH',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->runMigration();

        $this->assertFalse($this->isWrittenOff($partiallyPaidOldInvoice));
    }

    public function test_fully_paid_invoice_before_cutoff_is_left_untouched(): void
    {
        $fullyPaidOldInvoice = $this->makeInvoiceCreatedAt('2026-02-10 10:00:00', 5000);

        Payment::create([
            'sales_invoice_id' => $fullyPaidOldInvoice->id,
            'amount' => 5000,
            'payment_method' => 'CASH',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->runMigration();

        $this->assertFalse($this->isWrittenOff($fullyPaidOldInvoice));
    }

    public function test_migration_is_idempotent_and_keeps_original_write_off_timestamp(): void
    {
        $invoice = $this->makeInvoiceCreatedAt('2026-01-15 10:00:00');

        $this->runMigration();
        $firstWriteOffTimestamp = SalesInvoice::query()->withWrittenOff()->findOrFail($invoice->id)->written_off_at;

        Carbon::setTestNow(Carbon::parse($firstWriteOffTimestamp)->addDay());
        $this->runMigration();
        Carbon::setTestNow();

        $secondWriteOffTimestamp = SalesInvoice::query()->withWrittenOff()->findOrFail($invoice->id)->written_off_at;
        $this->assertTrue($firstWriteOffTimestamp->equalTo($secondWriteOffTimestamp));
    }

    public function test_migration_does_nothing_when_no_invoice_exists(): void
    {
        $this->runMigration();

        $this->assertSame(0, SalesInvoice::query()->withWrittenOff()->count());
    }

    public function test_only_eligible_invoices_are_written_off_among_a_mix(): void
    {
        $eligibleInvoice = $this->makeInvoiceCreatedAt('2026-03-10 10:00:00', 3000);
        $paidInvoice = $this->makeInvoiceCreatedAt('2026-03-10 10:00:00', 3000);
        $newInvoice = $this->makeInvoiceCreatedAt('2026-04-02 10:00:00', 3000);

        Payment::create([
            'sales_invoice_id' => $paidInvoice->id,
            'amount' => 3000,
            'payment_method' => 'CASH',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->runMigration();

        $writtenOffInvoiceIds = SalesInvoice::query()->onlyWrittenOff()->pluck('id')->all();
        $this->assertSame([$eligibleInvoice->id], $writtenOffInvoiceIds);
        $this->assertFalse($this->isWrittenOff($paidInvoice));
        $this->assertFalse($this->isWrittenOff($newInvoice));
    }
}
