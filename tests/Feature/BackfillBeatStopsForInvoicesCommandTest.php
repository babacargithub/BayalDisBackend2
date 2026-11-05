<?php

namespace Tests\Feature;

use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillBeatStopsForInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_DATE = '2026-06-15'; // Monday

    private Commercial $commercial;

    private Beat $beat;

    private BeatRound $round;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commercial = Commercial::create([
            'name' => 'Commercial Backfill',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->beat = Beat::create([
            'name' => 'Beat Backfill',
            'day_of_week' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
        ]);

        $this->round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
            'name' => 'Beat Backfill - '.self::ROUND_DATE,
        ]);
    }

    public function test_unpaid_invoice_completes_stop_and_recomputes_strike_rate(): void
    {
        $buyingCustomer = $this->makeCustomerWithStop('221700000010');
        $this->makeCustomerWithStop('221700000011');
        $this->makeInvoiceOnRoundDate($buyingCustomer);

        $this->artisan('bayal:backfill-beat-stops-for-invoices')->assertSuccessful();

        $stop = BeatStop::where('customer_id', $buyingCustomer->id)->firstOrFail();
        $this->assertSame(BeatStop::STATUS_COMPLETED, $stop->status);
        $this->assertTrue($stop->resulted_in_sale);
        $this->assertSame(50.0, $this->round->fresh()->strike_rate);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $buyingCustomer = $this->makeCustomerWithStop('221700000010');
        $this->makeInvoiceOnRoundDate($buyingCustomer);

        $this->artisan('bayal:backfill-beat-stops-for-invoices --dry-run')->assertSuccessful();

        $this->assertSame(
            BeatStop::STATUS_PLANNED,
            BeatStop::where('customer_id', $buyingCustomer->id)->firstOrFail()->status,
        );
        $this->assertNull($this->round->fresh()->strike_rate);
    }

    public function test_invoice_unpaid_in_round_a_and_paid_later_in_round_b_completes_round_a_stop(): void
    {
        $buyingCustomer = $this->makeCustomerWithStop('221700000010');
        $this->makeCustomerWithStop('221700000011');
        $invoice = $this->makeInvoiceOnRoundDate($buyingCustomer);

        $laterPayment = Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 100,
            'payment_method' => 'cash',
            'user_id' => $this->commercial->user_id,
        ]);
        DB::table('payments')->where('id', $laterPayment->id)->update([
            'created_at' => '2026-06-22 10:00:00',
            'updated_at' => '2026-06-22 10:00:00',
        ]);

        $this->artisan('bayal:backfill-beat-stops-for-invoices')->assertSuccessful();

        $roundAStop = BeatStop::where('beat_round_id', $this->round->id)
            ->where('customer_id', $buyingCustomer->id)
            ->firstOrFail();
        $this->assertSame(BeatStop::STATUS_COMPLETED, $roundAStop->status);
        $this->assertTrue($roundAStop->resulted_in_sale);
        $this->assertSame(50.0, $this->round->fresh()->strike_rate);
    }

    public function test_invoice_paid_on_the_spot_still_completes_its_stop(): void
    {
        $payingCustomer = $this->makeCustomerWithStop('221700000010');
        $invoice = $this->makeInvoiceOnRoundDate($payingCustomer);
        Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 100,
            'payment_method' => 'cash',
            'user_id' => $this->commercial->user_id,
        ]);

        $this->artisan('bayal:backfill-beat-stops-for-invoices')->assertSuccessful();

        $this->assertSame(
            BeatStop::STATUS_COMPLETED,
            BeatStop::where('customer_id', $payingCustomer->id)->firstOrFail()->status,
        );
    }

    public function test_running_twice_gives_the_same_result(): void
    {
        $buyingCustomer = $this->makeCustomerWithStop('221700000010');
        $this->makeCustomerWithStop('221700000011');
        $this->makeInvoiceOnRoundDate($buyingCustomer);

        $this->artisan('bayal:backfill-beat-stops-for-invoices')->assertSuccessful();
        $this->artisan('bayal:backfill-beat-stops-for-invoices')->assertSuccessful();

        $this->assertSame(50.0, $this->round->fresh()->strike_rate);
    }

    public function test_date_range_excludes_invoices_outside_it(): void
    {
        $buyingCustomer = $this->makeCustomerWithStop('221700000010');
        $this->makeInvoiceOnRoundDate($buyingCustomer);

        $this->artisan('bayal:backfill-beat-stops-for-invoices --from=2026-07-01')->assertSuccessful();

        $this->assertSame(
            BeatStop::STATUS_PLANNED,
            BeatStop::where('customer_id', $buyingCustomer->id)->firstOrFail()->status,
        );
    }

    private function makeCustomerWithStop(string $phoneNumber): Customer
    {
        $customer = Customer::create([
            'name' => 'Client '.$phoneNumber,
            'phone_number' => $phoneNumber,
            'owner_number' => $phoneNumber,
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $this->round->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $this->beat->templateStops()->create([
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        return $customer;
    }

    private function makeInvoiceOnRoundDate(Customer $customer): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
        ]);

        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'created_at' => self::ROUND_DATE.' 10:00:00',
            'updated_at' => self::ROUND_DATE.' 10:00:00',
        ]);

        return $invoice->fresh();
    }
}
