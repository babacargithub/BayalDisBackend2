<?php

namespace Tests\Feature;

use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\User;
use App\Services\BeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for BeatService::completeRoundStopForCustomerOnDate().
 *
 * When a sale or payment is recorded, the service completes:
 *   1. All past planned occurrence stops (rounds missed before this sale).
 *   2. The beat's next scheduled occurrence on or after the sale date
 *      (generated if it doesn't exist yet).
 *
 * This means a Sunday sale on a Monday beat correctly completes the upcoming
 * Monday round — not a phantom Sunday round.
 */
class BeatStopAutoCompleteTest extends TestCase
{
    use RefreshDatabase;

    // Concrete dates used across tests to avoid day-of-week ambiguity at runtime.
    private const MONDAY_PAST = '2026-06-08';   // Monday, one week before

    private const SUNDAY_SALE = '2026-06-14';   // Sunday (no beat scheduled)

    private const MONDAY_NEXT = '2026-06-15';   // Monday, the upcoming beat day

    private Commercial $commercial;

    private User $salespersonUser;

    private BeatService $beatService;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->salespersonUser = User::factory()->create();
        $this->commercial = Commercial::create([
            'name' => 'Test Commercial',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $this->salespersonUser->id,
        ]);
        $this->beatService = app(BeatService::class);
    }

    // =========================================================================
    // Core completion logic
    // =========================================================================

    public function test_sale_on_beat_day_completes_occurrence_stop_for_that_day(): void
    {
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        // Occurrence stop already exists for the sale day (e.g. round list was opened).
        $mondayStop = $this->makeOccurrenceStop($beat, $customer, self::MONDAY_NEXT);

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::MONDAY_NEXT);

        $mondayStop->refresh();
        $this->assertEquals(BeatStop::STATUS_COMPLETED, $mondayStop->status);
        $this->assertEquals('Terminé avec une vente', $mondayStop->notes);
        $this->assertTrue($mondayStop->resulted_in_sale);
        $this->assertNotNull($mondayStop->visited_at);
    }

    public function test_sale_before_beat_day_completes_next_occurrence_when_round_already_exists(): void
    {
        // Invoice on Sunday June 14, beat is Monday. When the Monday round was already
        // explicitly created, the sale should complete the Monday stop — not a Sunday one.
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        // Pre-create the Monday round explicitly (as required — rounds are never auto-created).
        $this->beatService->createRound($beat, self::MONDAY_NEXT);

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        $mondayStop = BeatStop::where('customer_id', $customer->id)
            ->whereNotNull('beat_round_id')
            ->whereHas('round', fn ($q) => $q->whereDate('planned_at', self::MONDAY_NEXT))
            ->firstOrFail();

        $this->assertEquals(BeatStop::STATUS_COMPLETED, $mondayStop->status);
        $this->assertEquals('Terminé avec une vente', $mondayStop->notes);
        $this->assertTrue($mondayStop->resulted_in_sale);

        // No phantom stop created for the wrong day (Sunday).
        $this->assertNull(
            BeatStop::where('customer_id', $customer->id)
                ->whereHas('round', fn ($q) => $q->whereDate('planned_at', self::SUNDAY_SALE))
                ->first()
        );
    }

    public function test_sale_does_not_create_next_occurrence_when_no_round_exists(): void
    {
        // Invoice on Sunday June 14, beat is Monday. If no round was created for Monday,
        // the sale must NOT auto-create one — it simply completes nothing for the future.
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        $this->assertCount(0, BeatStop::whereNotNull('beat_round_id')->get());

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        // No occurrence stops must have been created.
        $this->assertCount(0, BeatStop::whereNotNull('beat_round_id')->get());
    }

    public function test_sale_completes_past_stop_and_next_occurrence_when_both_rounds_exist(): void
    {
        // Customer had a planned stop on June 8 that was never visited, and the June 15
        // round was explicitly created. Invoice on Sunday June 14 → completes June 8 (past)
        // AND June 15 (next upcoming, because the round was pre-created).
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        $pastStop = $this->makeOccurrenceStop($beat, $customer, self::MONDAY_PAST);

        // Pre-create the next Monday round (explicit creation required).
        $this->beatService->createRound($beat, self::MONDAY_NEXT);

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        $pastStop->refresh();
        $this->assertEquals(BeatStop::STATUS_COMPLETED, $pastStop->status);

        $nextStop = BeatStop::where('customer_id', $customer->id)
            ->whereNotNull('beat_round_id')
            ->whereHas('round', fn ($q) => $q->whereDate('planned_at', self::MONDAY_NEXT))
            ->firstOrFail();
        $this->assertEquals(BeatStop::STATUS_COMPLETED, $nextStop->status);
    }

    public function test_sale_completes_only_past_stop_when_next_round_does_not_exist(): void
    {
        // Customer had a planned stop on June 8. No round was created for June 15.
        // Invoice on Sunday June 14 → completes June 8 but does NOT auto-create June 15.
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        $pastStop = $this->makeOccurrenceStop($beat, $customer, self::MONDAY_PAST);

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        $pastStop->refresh();
        $this->assertEquals(BeatStop::STATUS_COMPLETED, $pastStop->status);

        // No June 15 stop should have been auto-created.
        $this->assertNull(
            BeatStop::whereNotNull('beat_round_id')
                ->whereHas('round', fn ($q) => $q->whereDate('planned_at', self::MONDAY_NEXT))
                ->first()
        );
    }

    public function test_already_completed_stop_is_not_overridden(): void
    {
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        // June 15 stop was already completed manually before the invoice.
        $mondayStop = $this->makeOccurrenceStop($beat, $customer, self::MONDAY_NEXT);
        $mondayStop->complete(['notes' => 'Visité manuellement', 'resulted_in_sale' => false]);

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        $mondayStop->refresh();
        $this->assertEquals('Visité manuellement', $mondayStop->notes);
        $this->assertFalse($mondayStop->resulted_in_sale);
    }

    public function test_is_silent_when_customer_has_no_beat(): void
    {
        $customer = $this->makeCustomer();
        // No template stop → customer is not on any beat.

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        $this->assertCount(0, BeatStop::all());
    }

    public function test_future_occurrence_stops_beyond_next_are_not_affected(): void
    {
        // A stop exists for June 22 (two Mondays away). Only June 15 should be completed.
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        $farFutureStop = $this->makeOccurrenceStop($beat, $customer, '2026-06-22');

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        $farFutureStop->refresh();
        $this->assertEquals(BeatStop::STATUS_PLANNED, $farFutureStop->status);
    }

    public function test_cancelled_past_stop_is_not_re_completed(): void
    {
        // A past stop was cancelled (e.g. salesperson noted client was absent).
        // It must stay cancelled — only planned stops are transitioned.
        $customer = $this->makeCustomer();
        $beat = $this->makeMondayBeat();
        $this->addTemplateStop($beat, $customer);

        $cancelledPastStop = $this->makeOccurrenceStop($beat, $customer, self::MONDAY_PAST);
        $cancelledPastStop->update(['status' => BeatStop::STATUS_CANCELLED, 'notes' => 'Client absent']);

        $this->beatService->completeRoundStopForCustomerOnDate($customer->id, self::SUNDAY_SALE);

        $cancelledPastStop->refresh();
        $this->assertEquals(BeatStop::STATUS_CANCELLED, $cancelledPastStop->status);
        $this->assertEquals('Client absent', $cancelledPastStop->notes);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeMondayBeat(): Beat
    {
        return Beat::create([
            'name' => 'Beat Lundi '.uniqid(),
            'day_of_week' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    private function addTemplateStop(Beat $beat, Customer $customer): void
    {
        BeatStop::create([
            'beat_id' => $beat->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);
    }

    private function makeOccurrenceStop(Beat $beat, Customer $customer, string $date): BeatStop
    {
        $round = BeatRound::firstOrCreate(
            ['beat_id' => $beat->id, 'planned_at' => $date],
            [
                'name' => $beat->name.' - '.$date,
                'week_day' => $beat->day_of_week?->value,
                'commercial_id' => $beat->commercial_id,
            ],
        );

        return BeatStop::create([
            'beat_id' => $beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);
    }
}
