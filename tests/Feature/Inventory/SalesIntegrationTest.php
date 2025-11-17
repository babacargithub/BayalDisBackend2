<?php

namespace Tests\Feature\Inventory;

use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'manager@example.com'): User
    {
        return User::factory()->create([
            'email' => rand(1000,99999).$email,
        ]);
    }

    private function makeTeamWithManager(): Team
    {
        $manager = $this->makeUser();
        return Team::create([
            'name' => 'Team A'.rand(1000,99999),
            'user_id' => $manager->id,
        ]);
    }

    private function makeCommercialForTeam(Team $team): Commercial
    {
        $user = $this->makeUser('commercial@example.com');
        /** @var Commercial $commercial */
        $commercial = Commercial::create([
            'name' => 'Jean Dupont',
            'phone_number' => '221777777'.rand(1000,99999),
            'gender' => 'male',
            'user_id' => $user->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();
        return $commercial;
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Inventory Customer',
            'address' => 'Some Addr',
            'phone_number' => '221733333333',
            'owner_number' => '221733333333',
            'gps_coordinates' => '143,1292020,94009030404',
            'commercial_id' => $this->makeCommercialForTeam($this->makeTeamWithManager())->id,

        ]);
    }

    private function makeActiveCarLoadForTeam(Team $team): CarLoad
    {
        return CarLoad::create([
            'name' => 'CarLoad A',
            'team_id' => $team->id,
            'status' => 'ACTIVE',
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    public function test_add_and_remove_invoice_item_updates_carload_stock(): void
    {
        $team = $this->makeTeamWithManager();
        $commercial = $this->makeCommercialForTeam($team);
        $user = $commercial->user;
        $this->actingAs($user);

        $product = Product::create([
            'name' => 'Box 500ml',
            'price' => 1500,
            'cost_price' => 1000,
            'base_quantity' => 12,
        ]);

        $carLoad = $this->makeActiveCarLoadForTeam($team);
        /** @var CarLoadItem $item */
        $item = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 20,
            'quantity_left' => 20,
            'loaded_at' => now()->subHour(),
        ]);

        $customer = $this->makeCustomer();
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'paid' => false,
            'commercial_id' => $commercial->id,
        ]);

        // Add invoice item (sale of 5)
        $response = $this->post(route('sales-invoices.items.store', $invoice), [
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 1500,
        ]);
        $response->assertStatus(302);

        $item->refresh();
        $this->assertSame(15, $item->quantity_left, 'Adding invoice item should decrease quantity_left by 5');

        // Get created vente (first invoice item)
        $vente = $invoice->items()->first();

        // Remove invoice item and expect stock to be restored
        $response = $this->delete(route('sales-invoices.items.destroy', [$invoice, $vente]));
        $response->assertStatus(302);

        $item->refresh();
        $this->assertSame(20, $item->quantity_left, 'Removing invoice item should restore the stock');
    }

    public function test_single_vente_does_not_change_carload_stock_current_behavior(): void
    {
        // This test documents the current behavior of SINGLE ventes: they do not decrement car load stock
        $team = $this->makeTeamWithManager();
        $commercial = $this->makeCommercialForTeam($team);
        $user = $commercial->user;
        $this->actingAs($user);

        $product = Product::create([
            'name' => 'Box 750ml',
            'price' => 2000,
            'cost_price' => 1200,
            'base_quantity' => 12,
        ]);

        $carLoad = $this->makeActiveCarLoadForTeam($team);
        $item = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 10,
            'quantity_left' => 10,
            'loaded_at' => now()->subHour(),
        ]);

        $customer = $this->makeCustomer();

        // Create a SINGLE vente of quantity 7
        $response = $this->post(route('ventes.store'), [
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'quantity' => 7,
            'price' => 2000,
            'paid' => true,
            'should_be_paid_at' => null,
        ]);
        $response->assertStatus(302);

        // Current implementation does NOT decrement car load stock
        $item->refresh();
        $this->assertSame(10, $item->quantity_left, 'SINGLE vente currently does not affect car load stock');
    }
}
