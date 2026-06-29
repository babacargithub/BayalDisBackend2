<?php

namespace Tests\Feature\Feature;

use App\Enums\CarLoadStatus;
use App\Exceptions\OdometerNotRecordedException;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the odometer guard on POST /api/salesperson/sales-invoices.
 *
 * Business rule: a commercial must record their departure odometer reading
 * on a beat round for today before any sales invoice can be created.
 *
 * Covers:
 *  - No round for today → 422 with ODOMETER_NOT_RECORDED error_code
 *  - Round exists but odometer_start_km is null → 422 with ODOMETER_NOT_RECORDED
 *  - Round with odometer_start_km set → sale is allowed (201)
 *  - Correct error_code is returned so the mobile app can redirect
 */
class OdometerSalesGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Commercial $commercial;

    private CarLoad $carLoad;

    private Product $product;

    private Customer $customer;

    private Beat $beat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Équipe Test',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Agent Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);
        $this->commercial->team()->associate($team);
        $this->commercial->save();

        $this->product = Product::create([
            'name' => 'Produit Test',
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);

        $this->carLoad = CarLoad::create([
            'name' => 'Chargement Test',
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
            'load_date' => today()->subDay(),
            'return_date' => today()->addDays(30),
            'returned' => false,
        ]);

        $stockItem = new CarLoadItem;
        $stockItem->car_load_id = $this->carLoad->id;
        $stockItem->product_id = $this->product->id;
        $stockItem->quantity_loaded = 50;
        $stockItem->quantity_left = 50;
        $stockItem->loaded_at = today()->subDay()->setHour(7)->toDateTimeString();
        $stockItem->save();

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221700000099',
            'owner_number' => '221700000099',
            'gps_coordinates' => '14.69,17.44',
            'commercial_id' => $this->commercial->id,
        ]);

        $this->beat = Beat::create([
            'name' => 'Beat Test',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    private function postInvoice(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson('/api/salesperson/sales-invoices', [
                'customer_id' => $this->customer->id,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 1000]],
                'paid' => true,
                'payment_method' => 'CASH',
            ]);
    }

    // ─── No round for today ───────────────────────────────────────────────────

    public function test_sale_is_blocked_when_no_beat_round_exists_for_today(): void
    {
        $response = $this->postInvoice();

        $response->assertUnprocessable()
            ->assertJsonFragment(['error_code' => OdometerNotRecordedException::ERROR_CODE])
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'tournée'));
    }

    // ─── Round exists but no odometer ────────────────────────────────────────

    public function test_sale_is_blocked_when_round_exists_but_odometer_start_km_is_null(): void
    {
        BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => today(),
            'name' => 'Tournée '.today()->toDateString(),
            'commercial_id' => $this->commercial->id,
        ]);

        $response = $this->postInvoice();

        $response->assertUnprocessable()
            ->assertJsonFragment(['error_code' => OdometerNotRecordedException::ERROR_CODE])
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'kilométrage'));
    }

    // ─── Round with odometer → sale allowed ──────────────────────────────────

    public function test_sale_is_allowed_when_round_has_odometer_start_km_set(): void
    {
        $vehicle = Vehicle::factory()->create();

        BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => today(),
            'name' => 'Tournée '.today()->toDateString(),
            'commercial_id' => $this->commercial->id,
            'vehicle_id' => $vehicle->id,
            'odometer_start_km' => 12500,
        ]);

        $this->postInvoice()->assertCreated();
    }

    // ─── Error code is machine-readable ──────────────────────────────────────

    public function test_error_response_contains_machine_readable_error_code(): void
    {
        $this->assertSame('ODOMETER_NOT_RECORDED', OdometerNotRecordedException::ERROR_CODE);

        $response = $this->postInvoice();

        $response->assertJsonPath('error_code', OdometerNotRecordedException::ERROR_CODE);
    }
}
