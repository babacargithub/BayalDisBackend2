<?php

namespace Tests\Feature\VenteStats;

use App\Data\Vente\VenteStatsFilter;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for PaymentService::paymentsQuery() and ::sumPayments() with the new
 * invoice-level filters: teamId, beatId, and tagIds.
 *
 * Also covers the VenteStatsFilter fluent builder methods added in the same
 * refactor: inDateInterval, from, to, thatAreForTeam, forCustomersBelongingInBeat,
 * forCustomersHavingOneOfTags, and hasInvoiceLevelFilters.
 *
 * Financial-grade application — a wrong total means a real money loss.
 */
class PaymentServiceFilterTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $paymentService;

    private Product $defaultProduct;

    private Team $defaultTeam;

    private Commercial $defaultCommercial;

    private Customer $defaultCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = app(PaymentService::class);
        $this->defaultProduct = $this->makeProduct();
        $this->defaultTeam = $this->makeTeam();
        $this->defaultCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $this->defaultCustomer = $this->makeCustomer($this->defaultCommercial);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeProduct(int $price = 1000, int $costPrice = 500): Product
    {
        return Product::create([
            'name' => 'Product '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => 1,
        ]);
    }

    private function makeTeam(): Team
    {
        return Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeCommercialForTeam(Team $team): Commercial
    {
        $commercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();

        return $commercial;
    }

    private function makeCustomer(Commercial $commercial): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Dakar',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
        ]);
    }

    private function makeInvoiceWithPayment(
        int $amount,
        ?Commercial $commercial = null,
        ?Customer $customer = null,
        ?Carbon $paidAt = null,
    ): Payment {
        $commercial ??= $this->defaultCommercial;
        $customer ??= $this->defaultCustomer;

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'quantity' => 1,
            'price' => $amount,
            'profit' => (int) ($amount * 0.3),
            'type' => Vente::TYPE_INVOICE,
        ]);

        $payment = Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'CASH',
            'user_id' => User::factory()->create()->id,
        ]);

        if ($paidAt !== null) {
            $payment->created_at = $paidAt;
            $payment->save();
        }

        return $payment->fresh();
    }

    // =========================================================================
    // VenteStatsFilter fluent builder tests
    // =========================================================================

    public function test_vente_stats_filter_new_has_all_null_fields(): void
    {
        $filter = VenteStatsFilter::new();

        $this->assertNull($filter->commercialId);
        $this->assertNull($filter->customerId);
        $this->assertNull($filter->customerIds);
        $this->assertNull($filter->carLoadId);
        $this->assertNull($filter->teamId);
        $this->assertNull($filter->beatId);
        $this->assertNull($filter->tagIds);
        $this->assertNull($filter->startDate);
        $this->assertNull($filter->endDate);
    }

    public function test_in_date_interval_sets_both_dates_and_is_immutable(): void
    {
        $start = Carbon::parse('2025-06-01');
        $end = Carbon::parse('2025-06-30');
        $original = VenteStatsFilter::new();

        $filtered = $original->inDateInterval($start, $end);

        $this->assertNull($original->startDate, 'Original filter must not be mutated');
        $this->assertEquals($start, $filtered->startDate);
        $this->assertEquals($end, $filtered->endDate);
    }

    public function test_in_date_interval_allows_null_end_date(): void
    {
        $start = Carbon::parse('2025-06-01');

        $filtered = VenteStatsFilter::new()->inDateInterval($start, null);

        $this->assertEquals($start, $filtered->startDate);
        $this->assertNull($filtered->endDate);
    }

    public function test_from_sets_only_start_date_and_is_immutable(): void
    {
        $start = Carbon::parse('2025-06-15');
        $original = VenteStatsFilter::new();

        $filtered = $original->from($start);

        $this->assertNull($original->startDate, 'Original filter must not be mutated');
        $this->assertEquals($start, $filtered->startDate);
        $this->assertNull($filtered->endDate);
    }

    public function test_to_sets_only_end_date_and_is_immutable(): void
    {
        $end = Carbon::parse('2025-06-30');
        $original = VenteStatsFilter::new();

        $filtered = $original->to($end);

        $this->assertNull($original->endDate, 'Original filter must not be mutated');
        $this->assertNull($filtered->startDate);
        $this->assertEquals($end, $filtered->endDate);
    }

    public function test_that_are_for_team_sets_team_id_and_is_immutable(): void
    {
        $original = VenteStatsFilter::new();

        $filtered = $original->thatAreForTeam($this->defaultTeam);

        $this->assertNull($original->teamId, 'Original filter must not be mutated');
        $this->assertSame($this->defaultTeam->id, $filtered->teamId);
    }

    public function test_for_customers_belonging_in_beat_sets_beat_id_and_is_immutable(): void
    {
        $beat = Beat::create(['name' => 'Beat Test', 'commercial_id' => $this->defaultCommercial->id]);
        $original = VenteStatsFilter::new();

        $filtered = $original->forCustomersBelongingInBeat($beat);

        $this->assertNull($original->beatId, 'Original filter must not be mutated');
        $this->assertSame($beat->id, $filtered->beatId);
    }

    public function test_for_customers_having_one_of_tags_sets_tag_ids_and_is_immutable(): void
    {
        $original = VenteStatsFilter::new();

        $filtered = $original->forCustomersHavingOneOfTags([1, 2, 3]);

        $this->assertNull($original->tagIds, 'Original filter must not be mutated');
        $this->assertSame([1, 2, 3], $filtered->tagIds);
    }

    public function test_has_invoice_level_filters_returns_false_for_blank_filter(): void
    {
        $this->assertFalse(VenteStatsFilter::new()->hasInvoiceLevelFilters());
    }

    public function test_has_invoice_level_filters_returns_true_when_commercial_id_is_set(): void
    {
        $this->assertTrue(VenteStatsFilter::new()->thatAreMadeByCommercial(1)->hasInvoiceLevelFilters());
    }

    public function test_has_invoice_level_filters_returns_true_when_team_id_is_set(): void
    {
        $this->assertTrue(VenteStatsFilter::new()->thatAreForTeam($this->defaultTeam)->hasInvoiceLevelFilters());
    }

    public function test_has_invoice_level_filters_returns_true_when_beat_id_is_set(): void
    {
        $beat = Beat::create(['name' => 'Beat HIF', 'commercial_id' => $this->defaultCommercial->id]);
        $this->assertTrue(VenteStatsFilter::new()->forCustomersBelongingInBeat($beat)->hasInvoiceLevelFilters());
    }

    public function test_has_invoice_level_filters_returns_true_when_tag_ids_is_set(): void
    {
        $this->assertTrue(VenteStatsFilter::new()->forCustomersHavingOneOfTags([1])->hasInvoiceLevelFilters());
    }

    public function test_date_only_filter_does_not_trigger_invoice_level_scope(): void
    {
        $filter = VenteStatsFilter::new()->inDateInterval(Carbon::yesterday(), Carbon::today());
        $this->assertFalse($filter->hasInvoiceLevelFilters());
    }

    // =========================================================================
    // PaymentService::sumPayments — teamId filter
    // =========================================================================

    public function test_sum_payments_filtered_by_team_id_returns_only_payments_for_that_team(): void
    {
        $otherTeam = $this->makeTeam();
        $otherCommercial = $this->makeCommercialForTeam($otherTeam);
        $otherCustomer = $this->makeCustomer($otherCommercial);

        $this->makeInvoiceWithPayment(amount: 2000);                                       // defaultTeam
        $this->makeInvoiceWithPayment(amount: 3000, commercial: $otherCommercial, customer: $otherCustomer); // otherTeam

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->thatAreForTeam($this->defaultTeam)
        );

        $this->assertSame(2000, $total);
    }

    public function test_sum_payments_by_team_returns_zero_when_team_has_no_payments(): void
    {
        $emptyTeam = $this->makeTeam();

        $this->makeInvoiceWithPayment(amount: 5000); // defaultTeam — must not be counted

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->thatAreForTeam($emptyTeam)
        );

        $this->assertSame(0, $total);
    }

    public function test_sum_payments_by_team_aggregates_multiple_payments_across_commercials(): void
    {
        $commercialB = $this->makeCommercialForTeam($this->defaultTeam);
        $customerB = $this->makeCustomer($commercialB);

        $this->makeInvoiceWithPayment(amount: 1000);
        $this->makeInvoiceWithPayment(amount: 2000, commercial: $commercialB, customer: $customerB);

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->thatAreForTeam($this->defaultTeam)
        );

        $this->assertSame(3000, $total);
    }

    // =========================================================================
    // PaymentService::sumPayments — beatId filter
    // =========================================================================

    public function test_sum_payments_filtered_by_beat_id_returns_only_payments_for_customers_in_beat(): void
    {
        $beat = Beat::create(['name' => 'Beat A', 'commercial_id' => $this->defaultCommercial->id]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $this->defaultCustomer->id, 'visit_date' => null]);

        $customerNotInBeat = $this->makeCustomer($this->defaultCommercial);

        $this->makeInvoiceWithPayment(amount: 4000);                                      // customer in beat
        $this->makeInvoiceWithPayment(amount: 9000, customer: $customerNotInBeat);        // not in beat

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->forCustomersBelongingInBeat($beat)
        );

        $this->assertSame(4000, $total);
    }

    public function test_sum_payments_by_beat_includes_all_customers_in_the_beat(): void
    {
        $beat = Beat::create(['name' => 'Beat Multi', 'commercial_id' => $this->defaultCommercial->id]);
        $customerB = $this->makeCustomer($this->defaultCommercial);

        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $this->defaultCustomer->id, 'visit_date' => null]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => null]);

        $this->makeInvoiceWithPayment(amount: 1500);
        $this->makeInvoiceWithPayment(amount: 2500, customer: $customerB);

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->forCustomersBelongingInBeat($beat)
        );

        $this->assertSame(4000, $total);
    }

    public function test_sum_payments_by_beat_returns_zero_for_empty_beat(): void
    {
        $beat = Beat::create(['name' => 'Beat Empty', 'commercial_id' => $this->defaultCommercial->id]);

        $this->makeInvoiceWithPayment(amount: 5000); // customer not in beat

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->forCustomersBelongingInBeat($beat)
        );

        $this->assertSame(0, $total);
    }

    // =========================================================================
    // PaymentService::sumPayments — tagIds filter
    // =========================================================================

    public function test_sum_payments_filtered_by_tag_ids_returns_only_payments_for_tagged_customers(): void
    {
        $tag = CustomerTag::create(['name' => 'Fidèle', 'color' => '#FF0000']);

        $taggedCustomer = $this->makeCustomer($this->defaultCommercial);
        $taggedCustomer->tags()->attach($tag->id);

        $untaggedCustomer = $this->makeCustomer($this->defaultCommercial);

        $this->makeInvoiceWithPayment(amount: 3000, customer: $taggedCustomer);
        $this->makeInvoiceWithPayment(amount: 7000, customer: $untaggedCustomer);

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->forCustomersHavingOneOfTags([$tag->id])
        );

        $this->assertSame(3000, $total);
    }

    public function test_sum_payments_by_tags_matches_any_of_the_given_tag_ids(): void
    {
        $tagA = CustomerTag::create(['name' => 'VIP', 'color' => '#FF0000']);
        $tagB = CustomerTag::create(['name' => 'Nouveau', 'color' => '#00FF00']);

        $customerWithTagA = $this->makeCustomer($this->defaultCommercial);
        $customerWithTagA->tags()->attach($tagA->id);

        $customerWithTagB = $this->makeCustomer($this->defaultCommercial);
        $customerWithTagB->tags()->attach($tagB->id);

        $customerWithNoTag = $this->makeCustomer($this->defaultCommercial);

        $this->makeInvoiceWithPayment(amount: 1000, customer: $customerWithTagA);
        $this->makeInvoiceWithPayment(amount: 2000, customer: $customerWithTagB);
        $this->makeInvoiceWithPayment(amount: 9000, customer: $customerWithNoTag);

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->forCustomersHavingOneOfTags([$tagA->id, $tagB->id])
        );

        $this->assertSame(3000, $total);
    }

    public function test_sum_payments_by_tags_returns_zero_when_no_tagged_customers_have_payments(): void
    {
        $tag = CustomerTag::create(['name' => 'Inactif', 'color' => '#AAAAAA']);

        $this->makeInvoiceWithPayment(amount: 5000); // untagged customer

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->forCustomersHavingOneOfTags([$tag->id])
        );

        $this->assertSame(0, $total);
    }

    // =========================================================================
    // PaymentService::sumPayments — date range filter
    // =========================================================================

    public function test_sum_payments_with_date_interval_includes_payments_in_range(): void
    {
        $startOfRange = Carbon::parse('2025-06-01')->startOfDay();
        $endOfRange = Carbon::parse('2025-06-30')->endOfDay();

        $paymentInRange = $this->makeInvoiceWithPayment(amount: 4000, paidAt: Carbon::parse('2025-06-15'));
        $paymentOutsideRange = $this->makeInvoiceWithPayment(amount: 9000, paidAt: Carbon::parse('2025-07-01'));

        $total = $this->paymentService->sumPayments(
            VenteStatsFilter::new()->inDateInterval($startOfRange, $endOfRange)
        );

        $this->assertSame(4000, $total);
    }

    public function test_sum_payments_with_from_filter_includes_payments_on_or_after_start(): void
    {
        $start = Carbon::parse('2025-06-15')->startOfDay();

        $paymentBefore = $this->makeInvoiceWithPayment(amount: 9000, paidAt: Carbon::parse('2025-06-14'));
        $paymentOnStart = $this->makeInvoiceWithPayment(amount: 3000, paidAt: Carbon::parse('2025-06-15'));
        $paymentAfter = $this->makeInvoiceWithPayment(amount: 2000, paidAt: Carbon::parse('2025-06-20'));

        $total = $this->paymentService->sumPayments(VenteStatsFilter::new()->from($start));

        $this->assertSame(5000, $total);
    }

    // =========================================================================
    // PaymentService::paymentsQuery — returns a builder (no eager loading)
    // =========================================================================

    public function test_payments_query_returns_builder_that_can_be_further_scoped(): void
    {
        $this->makeInvoiceWithPayment(amount: 1000);
        $this->makeInvoiceWithPayment(amount: 2000);

        $query = $this->paymentService->paymentsQuery(VenteStatsFilter::new());

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $query);
        $this->assertSame(2, $query->count());
        $this->assertSame(3000, (int) $query->sum('amount'));
    }
}
