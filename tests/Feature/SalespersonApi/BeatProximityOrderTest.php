<?php

namespace Tests\Feature\SalespersonApi;

use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use App\Services\BeatService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for proximity-based display_position ordering on beat template stops.
 *
 * When customers are added to or removed from a beat, display_position is
 * recalculated so that the customer closest to the warehouse gets position 0,
 * the next closest position 1, and so on.
 *
 * Warehouse reference point: 14.753016680035563, -17.468550395271897
 */
class BeatProximityOrderTest extends TestCase
{
    use RefreshDatabase;

    // Approximate distances from the warehouse:
    //   CLOSE_GPS  ≈ 0.5 km
    //   MEDIUM_GPS ≈ 2.0 km
    //   FAR_GPS    ≈ 5.0 km
    private const CLOSE_GPS = '14.7575,-17.4690';

    private const MEDIUM_GPS = '14.7350,-17.4550';

    private const FAR_GPS = '14.7080,-17.4400';

    private User $user;

    private Commercial $commercial;

    private Beat $beat;

    private BeatService $beatService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $team = Team::create(['name' => 'Test Team', 'user_id' => User::factory()->create()->id]);
        $this->commercial = Commercial::create([
            'name' => 'Test Commercial',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);
        $this->beat = Beat::create([
            'name' => 'Beat Proximity Test',
            'day_of_week' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
        ]);
        $this->beatService = app(BeatService::class);
    }

    public function test_customers_are_sorted_closest_to_warehouse_first_when_added(): void
    {
        $closeCustomer = $this->makeCustomerWithGps(self::CLOSE_GPS);
        $farCustomer = $this->makeCustomerWithGps(self::FAR_GPS);
        $mediumCustomer = $this->makeCustomerWithGps(self::MEDIUM_GPS);

        // Add in non-proximity order — service must sort them.
        $this->beatService->addCustomersToBeat($this->beat, [
            $farCustomer->id,
            $closeCustomer->id,
            $mediumCustomer->id,
        ]);

        $this->assertDisplayPositions([
            $closeCustomer->id => 0,
            $mediumCustomer->id => 1,
            $farCustomer->id => 2,
        ]);
    }

    public function test_adding_a_closer_customer_later_resorts_all_positions(): void
    {
        $farCustomer = $this->makeCustomerWithGps(self::FAR_GPS);
        $mediumCustomer = $this->makeCustomerWithGps(self::MEDIUM_GPS);

        $this->beatService->addCustomersToBeat($this->beat, [$farCustomer->id, $mediumCustomer->id]);

        // medium=0, far=1 at this point. Now add a closer customer.
        $closeCustomer = $this->makeCustomerWithGps(self::CLOSE_GPS);
        $this->beatService->addCustomersToBeat($this->beat, [$closeCustomer->id]);

        $this->assertDisplayPositions([
            $closeCustomer->id => 0,
            $mediumCustomer->id => 1,
            $farCustomer->id => 2,
        ]);
    }

    public function test_customer_without_gps_coordinates_is_placed_last(): void
    {
        $closeCustomer = $this->makeCustomerWithGps(self::CLOSE_GPS);
        $noGpsCustomer = $this->makeCustomerWithGps(''); // empty = no GPS recorded

        $this->beatService->addCustomersToBeat($this->beat, [$noGpsCustomer->id, $closeCustomer->id]);

        $this->assertDisplayPositions([
            $closeCustomer->id => 0,
            $noGpsCustomer->id => 1,
        ]);
    }

    public function test_removing_a_customer_resorts_remaining_stops(): void
    {
        $closeCustomer = $this->makeCustomerWithGps(self::CLOSE_GPS);
        $mediumCustomer = $this->makeCustomerWithGps(self::MEDIUM_GPS);
        $farCustomer = $this->makeCustomerWithGps(self::FAR_GPS);

        $this->beatService->addCustomersToBeat($this->beat, [
            $farCustomer->id, $closeCustomer->id, $mediumCustomer->id,
        ]);

        // Remove the closest customer via the API endpoint.
        $this->actingAs($this->user)
            ->deleteJson("/api/beats/{$this->beat->id}/customers/{$closeCustomer->id}")
            ->assertOk();

        $this->assertDisplayPositions([
            $mediumCustomer->id => 0,
            $farCustomer->id => 1,
        ]);
    }

    public function test_occurrence_stops_inherit_proximity_order_from_template(): void
    {
        $closeCustomer = $this->makeCustomerWithGps(self::CLOSE_GPS);
        $farCustomer = $this->makeCustomerWithGps(self::FAR_GPS);

        $this->beatService->addCustomersToBeat($this->beat, [$farCustomer->id, $closeCustomer->id]);

        // Generate occurrence stops for a future Monday.
        $this->beat->getOrGenerateStopsForDate(Carbon::parse('2026-06-15')->startOfDay());

        $closeOccurrence = BeatStop::where('customer_id', $closeCustomer->id)->whereNotNull('visit_date')->firstOrFail();
        $farOccurrence = BeatStop::where('customer_id', $farCustomer->id)->whereNotNull('visit_date')->firstOrFail();

        $this->assertEquals(0, $closeOccurrence->display_position);
        $this->assertEquals(1, $farOccurrence->display_position);
    }

    public function test_idempotent_add_does_not_change_existing_positions(): void
    {
        $closeCustomer = $this->makeCustomerWithGps(self::CLOSE_GPS);
        $farCustomer = $this->makeCustomerWithGps(self::FAR_GPS);

        $this->beatService->addCustomersToBeat($this->beat, [$farCustomer->id, $closeCustomer->id]);

        // Re-adding the same customers must be a no-op.
        $this->beatService->addCustomersToBeat($this->beat, [$farCustomer->id, $closeCustomer->id]);

        $this->assertDisplayPositions([
            $closeCustomer->id => 0,
            $farCustomer->id => 1,
        ]);
    }

    public function test_fetching_template_customers_sorts_by_proximity_even_when_display_position_was_never_set(): void
    {
        // Simulate pre-existing data: stops inserted directly without display_position.
        $closeCustomer = $this->makeCustomerWithGps(self::CLOSE_GPS);
        $farCustomer = $this->makeCustomerWithGps(self::FAR_GPS);
        $mediumCustomer = $this->makeCustomerWithGps(self::MEDIUM_GPS);

        foreach ([$farCustomer, $closeCustomer, $mediumCustomer] as $customer) {
            BeatStop::create([
                'beat_id' => $this->beat->id,
                'customer_id' => $customer->id,
                'status' => BeatStop::STATUS_PLANNED,
                'visit_date' => null,
                // display_position intentionally omitted → NULL
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson("/api/beats/{$this->beat->id}/customers")
            ->assertOk();

        $returnedIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertEquals(
            [$closeCustomer->id, $mediumCustomer->id, $farCustomer->id],
            $returnedIds,
            'Customers must be returned closest-first after lazy proximity recalculation.'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeCustomerWithGps(string $gpsCoordinates): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => $gpsCoordinates,
            'commercial_id' => $this->commercial->id,
        ]);
    }

    /**
     * @param  array<int, int>  $expectedPositionsByCustomerId
     */
    private function assertDisplayPositions(array $expectedPositionsByCustomerId): void
    {
        foreach ($expectedPositionsByCustomerId as $customerId => $expectedPosition) {
            $stop = BeatStop::where('beat_id', $this->beat->id)
                ->where('customer_id', $customerId)
                ->whereNull('visit_date')
                ->firstOrFail();

            $this->assertEquals(
                $expectedPosition,
                $stop->display_position,
                "Customer {$customerId} expected at position {$expectedPosition}, got {$stop->display_position}"
            );
        }
    }
}
