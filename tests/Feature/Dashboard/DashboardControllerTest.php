<?php

namespace Tests\Feature\Dashboard;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Depense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\TypeDepense;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP / Inertia response tests for DashboardController.
 *
 * These tests verify that the Dashboard.vue component receives the correct
 * props from the server — i.e. that what the user sees on screen matches
 * what the database contains.
 *
 * Strategy: seed a known state, hit GET /dashboard, assert that every
 * prop key the Vue template reads is present and holds the expected value.
 *
 * The "single source of truth" contract:
 *   - `dailyStats`, `weeklyStats`, `monthlyStats`, `overallStats` are all
 *     snake_case arrays built from DashboardStats::toSnakeCaseArray().
 *   - `selectedDate` is the ISO date string used to drive the date picker.
 *
 * If any of these keys disappear or hold the wrong value, the dashboard
 * will silently show zeros or undefined to the user.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $authenticatedUser;

    private Commercial $defaultCommercial;

    private Customer $defaultCustomer;

    private Product $defaultProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser = User::factory()->create();
        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => $this->authenticatedUser->id,
        ]);
        $this->defaultCommercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->authenticatedUser->id,
            'team_id' => $team->id,
        ]);
        $this->defaultCustomer = Customer::create([
            'name' => 'Customer Test',
            'address' => 'Test Address',
            'phone_number' => '221700000002',
            'owner_number' => '221700000003',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->defaultCommercial->id,
        ]);
        $this->defaultProduct = Product::create([
            'name' => 'Product Test',
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeInvoiceWithOneItem(
        int $price,
        int $quantity,
        int $profit,
        bool $paid = false,
    ): SalesInvoice {
        $invoice = SalesInvoice::create([
            'customer_id' => $this->defaultCustomer->id,
            'commercial_id' => $this->defaultCommercial->id,
            'paid' => $paid,
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'commercial_id' => $this->defaultCommercial->id,
            'quantity' => $quantity,
            'price' => $price,
            'profit' => $profit,
            'paid' => $paid,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh(['items', 'payments']);
    }

    private function backdateInvoice(SalesInvoice $invoice, Carbon $date): void
    {
        $timestamp = $date->copy()->startOfDay()->addHours(9);
        $invoice->created_at = $timestamp;
        $invoice->save();
        $invoice->items()->each(function (Vente $vente) use ($timestamp) {
            $vente->created_at = $timestamp;
            $vente->save();
        });
    }

    private function makePayment(SalesInvoice $invoice, int $amount): Payment
    {
        return Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'Cash',
            'user_id' => $this->authenticatedUser->id,
        ]);
    }

    private function backdatePayment(Payment $payment, Carbon $date): void
    {
        $payment->created_at = $date->copy()->startOfDay()->addHours(10);
        $payment->save();
    }

    private function makeDepense(int $amount): Depense
    {
        $typeDepense = TypeDepense::firstOrCreate(['name' => 'Test Type']);

        return Depense::create([
            'amount' => $amount,
            'type_depense_id' => $typeDepense->id,
            'comment' => 'Test expense',
        ]);
    }

    private function backdateDepense(Depense $depense, Carbon $date): void
    {
        $depense->created_at = $date->copy()->startOfDay()->addHours(8);
        $depense->save();
    }

    // =========================================================================
    // Authentication guard
    // =========================================================================

    public function test_unauthenticated_user_is_redirected_away_from_dashboard(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertStatus(200);
    }

    // =========================================================================
    // Inertia component and prop structure
    // =========================================================================

    public function test_dashboard_renders_the_correct_inertia_component(): void
    {
        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_dashboard_response_contains_all_four_stat_blocks_and_selected_date(): void
    {
        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('dailyStats')
            ->has('weeklyStats')
            ->has('monthlyStats')
            ->has('overallStats')
            ->has('selectedDate')
        );
    }

    public function test_each_stat_block_contains_all_keys_that_the_vue_template_reads(): void
    {
        $expectedKeys = [
            'total_customers',
            'total_prospects',
            'total_confirmed_customers',
            'sales_invoices_count',
            'fully_paid_sales_invoices_count',
            'partially_paid_sales_invoices_count',
            'unpaid_sales_invoices_count',
            'total_sales',
            'total_estimated_profit',
            'total_realized_profit',
            'total_payments_received',
            'total_expenses',
        ];

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        foreach (['dailyStats', 'weeklyStats', 'monthlyStats', 'overallStats'] as $statBlock) {
            foreach ($expectedKeys as $key) {
                $response->assertInertia(fn ($page) => $page
                    ->has("{$statBlock}.{$key}")
                );
            }
        }
    }

    // =========================================================================
    // selectedDate default and query-param override
    // =========================================================================

    public function test_selected_date_defaults_to_today_when_no_query_param_is_given(): void
    {
        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('selectedDate', Carbon::today()->toDateString())
        );
    }

    public function test_selected_date_reflects_the_date_query_param_when_provided(): void
    {
        $specificDate = '2025-06-15';

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $specificDate]));

        $response->assertInertia(fn ($page) => $page
            ->where('selectedDate', $specificDate)
        );
    }

    // =========================================================================
    // overallStats — all-time values match the seeded data
    // =========================================================================

    public function test_overall_stats_total_sales_equals_sum_of_all_invoice_items(): void
    {
        $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 400); // 4 000
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 3, profit: 300); // 3 000

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.total_sales', 7000)
        );
    }

    public function test_overall_stats_sales_invoices_count_equals_number_of_invoices(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.sales_invoices_count', 3)
        );
    }

    public function test_overall_stats_invoice_payment_status_breakdown_is_correct(): void
    {
        // 1 fully paid
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: true);

        // 1 partially paid
        $invoicePartial = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400, paid: false);
        $this->makePayment($invoicePartial, 1000);

        // 2 unpaid
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.fully_paid_sales_invoices_count', 1)
            ->where('overallStats.partially_paid_sales_invoices_count', 1)
            ->where('overallStats.unpaid_sales_invoices_count', 2)
            ->where('overallStats.sales_invoices_count', 4)
        );
    }

    public function test_overall_stats_total_estimated_profit_equals_sum_of_vente_profits(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 300);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 700);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.total_estimated_profit', 1000)
        );
    }

    public function test_overall_stats_total_realized_profit_equals_sum_of_payment_profits(): void
    {
        // Invoice 4000 total, 1600 profit (40% margin). Full payment → 1600 realized.
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1600);
        $this->makePayment($invoice, 4000);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.total_realized_profit', 1600)
        );
    }

    public function test_overall_stats_total_payments_received_equals_sum_of_payment_amounts(): void
    {
        $invoiceA = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400);
        $invoiceB = $this->makeInvoiceWithOneItem(price: 3000, quantity: 1, profit: 600);
        $this->makePayment($invoiceA, 2000);
        $this->makePayment($invoiceB, 1500);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.total_payments_received', 3500)
        );
    }

    public function test_overall_stats_total_expenses_equals_sum_of_depenses(): void
    {
        $this->makeDepense(5000);
        $this->makeDepense(3000);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.total_expenses', 8000)
        );
    }

    // =========================================================================
    // dailyStats — scoped to the selected date
    // =========================================================================

    public function test_daily_stats_only_counts_invoices_created_on_the_selected_date(): void
    {
        $selectedDate = Carbon::today()->subDays(3)->toDateString();

        $invoiceOnSelectedDate = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($invoiceOnSelectedDate, Carbon::parse($selectedDate));

        $invoiceOnDifferentDay = $this->makeInvoiceWithOneItem(price: 9000, quantity: 1, profit: 999);
        $this->backdateInvoice($invoiceOnDifferentDay, Carbon::today()->subDays(10));

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $selectedDate]));

        $response->assertInertia(fn ($page) => $page
            ->where('dailyStats.sales_invoices_count', 1)
            ->where('dailyStats.total_sales', 1000)
        );
    }

    public function test_daily_stats_are_zero_when_no_invoices_exist_on_the_selected_date(): void
    {
        $dateWithNoInvoices = Carbon::today()->subDays(5)->toDateString();

        // Create an invoice on a different date
        $invoiceOnDifferentDay = $this->makeInvoiceWithOneItem(price: 5000, quantity: 2, profit: 1000);
        $this->backdateInvoice($invoiceOnDifferentDay, Carbon::today()->subDays(10));

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $dateWithNoInvoices]));

        $response->assertInertia(fn ($page) => $page
            ->where('dailyStats.sales_invoices_count', 0)
            ->where('dailyStats.total_sales', 0)
            ->where('dailyStats.total_estimated_profit', 0)
            ->where('dailyStats.total_payments_received', 0)
            ->where('dailyStats.total_expenses', 0)
        );
    }

    public function test_daily_stats_payments_are_scoped_to_the_selected_date(): void
    {
        $selectedDate = Carbon::today()->subDays(2)->toDateString();

        $invoice = $this->makeInvoiceWithOneItem(price: 3000, quantity: 1, profit: 600);

        $paymentOnSelectedDate = $this->makePayment($invoice, 3000);
        $this->backdatePayment($paymentOnSelectedDate, Carbon::parse($selectedDate));

        $paymentOnDifferentDate = $this->makePayment($invoice, 3000);
        $this->backdatePayment($paymentOnDifferentDate, Carbon::today()->subDays(10));

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $selectedDate]));

        $response->assertInertia(fn ($page) => $page
            ->where('dailyStats.total_payments_received', 3000)
        );
    }

    public function test_daily_expenses_are_scoped_to_the_selected_date(): void
    {
        $selectedDate = Carbon::today()->subDays(2)->toDateString();

        $depenseOnSelectedDate = $this->makeDepense(4000);
        $this->backdateDepense($depenseOnSelectedDate, Carbon::parse($selectedDate));

        $depenseOnDifferentDate = $this->makeDepense(9000);
        $this->backdateDepense($depenseOnDifferentDate, Carbon::today()->subDays(10));

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $selectedDate]));

        $response->assertInertia(fn ($page) => $page
            ->where('dailyStats.total_expenses', 4000)
        );
    }

    // =========================================================================
    // weeklyStats — scoped to the ISO week of the selected date
    // =========================================================================

    public function test_weekly_stats_include_all_invoices_within_the_selected_week(): void
    {
        $selectedDate = Carbon::now();
        $startOfWeek = $selectedDate->copy()->startOfWeek();
        $endOfWeek = $selectedDate->copy()->endOfWeek();

        $invoiceInWeek = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($invoiceInWeek, $startOfWeek->copy()->addDays(1));

        $invoiceOutsideWeek = $this->makeInvoiceWithOneItem(price: 9000, quantity: 1, profit: 999);
        $this->backdateInvoice($invoiceOutsideWeek, $endOfWeek->copy()->addDays(2)); // next week

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $selectedDate->toDateString()]));

        $response->assertInertia(fn ($page) => $page
            ->where('weeklyStats.sales_invoices_count', 1)
            ->where('weeklyStats.total_sales', 1000)
        );
    }

    // =========================================================================
    // monthlyStats — scoped to the calendar month of the selected date
    // =========================================================================

    public function test_monthly_stats_include_all_invoices_within_the_selected_month(): void
    {
        $selectedDate = Carbon::now();
        $startOfMonth = $selectedDate->copy()->startOfMonth();

        $invoiceInMonth = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400);
        $this->backdateInvoice($invoiceInMonth, $startOfMonth->copy()->addDays(1));

        $invoiceOutsideMonth = $this->makeInvoiceWithOneItem(price: 9000, quantity: 1, profit: 999);
        $this->backdateInvoice($invoiceOutsideMonth, $startOfMonth->copy()->subDays(5)); // previous month

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $selectedDate->toDateString()]));

        $response->assertInertia(fn ($page) => $page
            ->where('monthlyStats.sales_invoices_count', 1)
            ->where('monthlyStats.total_sales', 2000)
        );
    }

    // =========================================================================
    // Period isolation — data in one period must not bleed into another
    // =========================================================================

    public function test_daily_stats_total_sales_differs_from_weekly_when_most_sales_are_on_other_days(): void
    {
        $today = Carbon::today();

        // 1 invoice today
        $invoiceToday = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($invoiceToday, $today);

        // 1 invoice earlier this week (but not today)
        $earlierThisWeek = $today->copy()->startOfWeek();
        if ($earlierThisWeek->isSameDay($today)) {
            // If today IS the start of the week, put the second invoice yesterday (previous week edge-case ignored)
            $earlierThisWeek = $today->copy()->subDays(1);
        }
        $invoiceEarlierThisWeek = $this->makeInvoiceWithOneItem(price: 5000, quantity: 1, profit: 1000);
        $this->backdateInvoice($invoiceEarlierThisWeek, $earlierThisWeek);

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('dashboard', ['date' => $today->toDateString()]));

        $response->assertInertia(function ($page) {
            $dailySales = $page->toArray()['props']['dailyStats']['total_sales'];
            $weeklySales = $page->toArray()['props']['weeklyStats']['total_sales'];

            $this->assertSame(1000, $dailySales, 'dailyStats must only include sales from today');
            $this->assertGreaterThan($dailySales, $weeklySales, 'weeklyStats must include more than just today');
        });
    }

    public function test_overall_stats_always_includes_all_periods(): void
    {
        $veryOldDate = Carbon::now()->subYears(2);
        $recentDate = Carbon::now()->subDays(3);

        $oldInvoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($oldInvoice, $veryOldDate);

        $recentInvoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400);
        $this->backdateInvoice($recentInvoice, $recentDate);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('overallStats.sales_invoices_count', 2)
            ->where('overallStats.total_sales', 3000)
        );
    }

    // =========================================================================
    // All stat values are integers (never null or string)
    // =========================================================================

    public function test_all_numeric_stat_values_are_integers_not_null_or_strings(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400);
        $this->makePayment($invoice, 2000);
        $this->makeDepense(3000);

        $response = $this->actingAs($this->authenticatedUser)->get(route('dashboard'));

        $props = $response->original->getData()['page']['props'];

        $numericKeys = [
            'sales_invoices_count',
            'fully_paid_sales_invoices_count',
            'partially_paid_sales_invoices_count',
            'unpaid_sales_invoices_count',
            'total_sales',
            'total_estimated_profit',
            'total_realized_profit',
            'total_payments_received',
            'total_expenses',
            'total_customers',
            'total_prospects',
            'total_confirmed_customers',
        ];

        foreach (['dailyStats', 'weeklyStats', 'monthlyStats', 'overallStats'] as $statBlock) {
            foreach ($numericKeys as $key) {
                $value = $props[$statBlock][$key];
                $this->assertIsInt(
                    $value,
                    "{$statBlock}.{$key} must be an integer — Vue will display 'NaN' or nothing if it is null or a string"
                );
            }
        }
    }
}
