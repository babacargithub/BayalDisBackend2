<?php

namespace Tests\Feature;

use App\Models\CarLoad;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\CarLoadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoicePutInStockTest extends TestCase
{
    use RefreshDatabase;

    private function seedBasicData(): array
    {
        $user = User::factory()->create();

        // Create a team because the controller expects Team::firstOrFail()
        $team = Team::create([
            'name' => 'Test Team',
            'user_id' => $user->id,
        ]);

        $carLoad = CarLoad::create([
            'name' => 'CL-TEST',
            'team_id' => $team->id,
            'load_date' => now(),
            'status' => \App\Enums\CarLoadStatus::Selling,
        ]);

        // Minimal supplier
        $supplier = Supplier::create([
            'name' => 'ACME Supplier',
            'contact' => 'John Doe',
            'email' => 'acme@example.com',
            'phone' => '0000000000',
            'address' => 'Somewhere',
            'tax_number' => 'TN-001',
        ]);

        // Create two products
        $p1 = Product::create([
            'name' => 'Product 1',
            'price' => 1000,
            'cost_price' => 700,
            'base_quantity' => 1,
        ]);
        $p2 = Product::create([
            'name' => 'Product 2',
            'price' => 2000,
            'cost_price' => 1200,
            'base_quantity' => 1,
        ]);

        // Purchase invoice with two items
        $invoice = PurchaseInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-1001',
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'comment' => 'Test invoice',
            'status' => 'pending',
            'is_draft' => false,
            'is_paid' => false,
            'is_stocked' => false,
        ]);

        $invoice->items()->create([
            'product_id' => $p1->id,
            'quantity' => 5,
            'unit_price' => 1000,
        ]);
        $invoice->items()->create([
            'product_id' => $p2->id,
            'quantity' => 3,
            'unit_price' => 2000,
        ]);

        return compact('user', 'team', 'supplier', 'p1', 'p2', 'invoice', 'carLoad');
    }

    public function test_put_items_to_stock_success_creates_stock_entries_and_marks_invoice(): void
    {
        $data = $this->seedBasicData();
        $this->actingAs($data['user']);

        // Bind a mock CarLoadService so that we do not hit real warehouse-decrement logic
        $mock = $this->getMockBuilder(CarLoadService::class)
            ->onlyMethods(['createItemsToCarLoad'])
            ->getMock();

        // Expect createItems to be called once with the real car load and array of items
        $mock->expects($this->once())
            ->method('createItemsToCarLoad')
            ->with(
                $this->callback(fn ($cl) => $cl instanceof CarLoad && $cl->id === $data['carLoad']->id),
                $this->callback(function ($items) {
                    // Should contain two items derived from invoice
                    if (! is_array($items) || count($items) !== 2) {
                        return false;
                    }
                    foreach ($items as $it) {
                        if (! isset($it['product_id'], $it['quantity_loaded'], $it['quantity_left'])) {
                            return false;
                        }
                    }

                    return true;
                })
            )
            ->willReturn($data['carLoad']);

        $this->app->instance(CarLoadService::class, $mock);

        $response = $this->post(
            route('purchase-invoices.put-in-stock', $data['invoice']),
            ['car_load_id' => $data['carLoad']->id]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Assert invoice is marked stocked
        $this->assertTrue($data['invoice']->fresh()->is_stocked);

        // Assert two stock entries created (mock prevents warehouse decrement so quantity_left stays full)
        $this->assertEquals(2, StockEntry::count());

        // Validate values of one of the entries
        $entry = StockEntry::where('product_id', $data['p1']->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals(5, $entry->quantity);
        $this->assertEquals(5, $entry->quantity_left);
        $this->assertEquals(1000, $entry->unit_price);
    }

    public function test_put_items_to_stock_returns_error_when_already_stocked(): void
    {
        $data = $this->seedBasicData();
        $this->actingAs($data['user']);

        // Mark as stocked before calling
        $data['invoice']->update(['is_stocked' => true]);

        // Even if service were bound, controller should exit early; bind a dummy to ensure not called
        $mock = $this->getMockBuilder(CarLoadService::class)
            ->onlyMethods(['createItemsToCarLoad'])
            ->getMock();
        $mock->expects($this->never())->method('createItemsToCarLoad');
        $this->app->instance(CarLoadService::class, $mock);

        $response = $this->post(route('purchase-invoices.put-in-stock', $data['invoice']), [
            'car_load_id' => $data['carLoad']->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();

        // No stock entries should be created
        $this->assertEquals(0, StockEntry::count());
    }

    public function test_put_items_to_stock_rolls_back_when_create_items_throws_exception(): void
    {
        $data = $this->seedBasicData();
        $this->actingAs($data['user']);

        // Bind a mock CarLoadService that throws to simulate a failure (e.g. insufficient stock)
        $mock = $this->getMockBuilder(CarLoadService::class)
            ->onlyMethods(['createItemsToCarLoad'])
            ->getMock();
        $mock->method('createItemsToCarLoad')
            ->willThrowException(new \Exception('Stock insuffisant'));
        $this->app->instance(CarLoadService::class, $mock);

        $response = $this->post(
            route('purchase-invoices.put-in-stock', $data['invoice']),
            ['car_load_id' => $data['carLoad']->id]
        );

        $response->assertRedirect();
        $response->assertSessionHasErrors();

        // Assert transaction rolled back: no stock entries and invoice not marked stocked
        $this->assertEquals(0, StockEntry::count());
        $this->assertFalse($data['invoice']->fresh()->is_stocked);
    }
}
