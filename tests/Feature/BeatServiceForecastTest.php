<?php

namespace Tests\Feature;

use App\Data\Beat\BeatForecastDTO;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\BeatService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for BeatService::computeForecastedSalesForBeat().
 *
 * The forecast is the arithmetic average of actual beat-customer sales on the
 * matching day-of-week occurrences within the past FORECAST_LOOKBACK_DAYS (15) days.
 *
 * All sales are queried exclusively via SalesInvoiceStatsService, which is the
 * single source of truth for financial aggregations in this application.
 */
class BeatServiceForecastTest extends TestCase
{
    use RefreshDatabase;

    private BeatService $beatService;

    private Product $defaultProduct;

    private Commercial $defaultCommercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beatService = app(BeatService::class);
        $this->defaultProduct = $this->makeProduct();
        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);
        $this->defaultCommercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $team->id,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeProduct(int $price = 1000, int $costPrice = 400): Product
    {
        return Product::create([
            'name' => 'Product '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => 1,
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
            'commercial_id' => $this->defaultCommercial->id,
        ]);
    }

    private function makeBeatForDayOfWeek(DayOfWeek $dayOfWeek): Beat
    {
        return Beat::create([
            'name' => 'Beat '.uniqid(),
            'day_of_week' => $dayOfWeek->value,
            'commercial_id' => $this->defaultCommercial->id,
        ]);
    }

    private function addTemplateBeatStop(Beat $beat, Customer $customer): void
    {
        BeatStop::create([
            'beat_id' => $beat->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
            'visit_date' => null,
        ]);
    }

    /**
     * Create a SalesInvoice + INVOICE_ITEM Vente for a customer backdated to the given date.
     *
     * Because created_at is not in the fillable arrays of SalesInvoice or Vente, we create
     * the records first and then backdate them via a direct DB update to bypass mass-assignment
     * protection while keeping the Eloquent model events intact.
     */
    private function createInvoiceWithSaleForCustomerOnDate(
        Customer $customer,
        Carbon $date,
        int $price,
        int $quantity = 1,
        ?Product $product = null,
        ?int $explicitProfit = null,
    ): SalesInvoice {
        $usedProduct = $product ?? $this->defaultProduct;
        $profit = $explicitProfit ?? ($price - $usedProduct->cost_price) * $quantity;
        $dateString = $date->toDateTimeString();

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->defaultCommercial->id,
        ]);

        DB::table('sales_invoices')
            ->where('id', $invoice->id)
            ->update(['created_at' => $dateString, 'updated_at' => $dateString]);

        $vente = Vente::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'product_id' => $usedProduct->id,
            'price' => $price,
            'quantity' => $quantity,
            'profit' => $profit,
            'type' => Vente::TYPE_INVOICE,
        ]);

        DB::table('ventes')
            ->where('id', $vente->id)
            ->update(['created_at' => $dateString, 'updated_at' => $dateString]);

        return $invoice->fresh();
    }

    /**
     * Find all dates within the past $lookbackDays days (not including today)
     * that fall on the given day of week, ordered most-recent first.
     *
     * @return Carbon[]
     */
    private function findPastDatesMatchingDayOfWeek(DayOfWeek $targetDayOfWeek, int $lookbackDays = 15): array
    {
        $matchingDates = [];
        $todayStart = now()->startOfDay();

        for ($daysBack = 1; $daysBack <= $lookbackDays; $daysBack++) {
            $candidateDate = $todayStart->copy()->subDays($daysBack);
            if (DayOfWeek::fromCarbon($candidateDate) === $targetDayOfWeek) {
                $matchingDates[] = $candidateDate;
            }
        }

        return $matchingDates;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_returns_zero_forecast_when_beat_has_no_template_stops(): void
    {
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Monday);
        // No template stops added — beat has no customers

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        $this->assertInstanceOf(BeatForecastDTO::class, $forecast);
        $this->assertSame(0, $forecast->forecastedTotalSales);
        $this->assertSame(0, $forecast->forecastedTotalProfit);
        $this->assertSame(0, $forecast->dataPointsCount);
    }

    public function test_returns_zero_sales_forecast_when_beat_customers_have_no_sales_in_lookback_window(): void
    {
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Monday);
        $customer = $this->makeCustomer();
        $this->addTemplateBeatStop($beat, $customer);
        // No invoices created at all

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        $this->assertSame(0, $forecast->forecastedTotalSales);
        $this->assertSame(0, $forecast->forecastedTotalProfit);
        // data_points_count is still > 0 because past date slots exist
        $this->assertGreaterThan(0, $forecast->dataPointsCount);
    }

    public function test_forecast_is_correct_average_across_past_beat_day_occurrences(): void
    {
        // Use Monday as the beat's recurring day.
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Monday);
        $customer = $this->makeCustomer();
        $this->addTemplateBeatStop($beat, $customer);

        $pastMondayDates = $this->findPastDatesMatchingDayOfWeek(DayOfWeek::Monday);
        $this->assertGreaterThanOrEqual(2, count($pastMondayDates), 'Expected at least 2 Mondays in the past 15 days');

        // Create sales only on the first two past Mondays
        $firstMondaySales = 50_000;
        $secondMondaySales = 70_000;

        $this->createInvoiceWithSaleForCustomerOnDate($customer, $pastMondayDates[0], $firstMondaySales);
        $this->createInvoiceWithSaleForCustomerOnDate($customer, $pastMondayDates[1], $secondMondaySales);

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        // The remaining past Mondays (if any) contribute 0 sales — average over all data points
        $totalSalesForAllDataPoints = $firstMondaySales + $secondMondaySales;
        $expectedForecastedSales = (int) round($totalSalesForAllDataPoints / $forecast->dataPointsCount);

        $this->assertSame($expectedForecastedSales, $forecast->forecastedTotalSales);
        $this->assertGreaterThan(0, $forecast->forecastedTotalSales);
        $this->assertGreaterThan(0, $forecast->forecastedTotalProfit);
    }

    public function test_forecast_data_points_count_reflects_number_of_matching_past_dates(): void
    {
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Wednesday);
        $customer = $this->makeCustomer();
        $this->addTemplateBeatStop($beat, $customer);

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        $expectedPastWednesdaysCount = count($this->findPastDatesMatchingDayOfWeek(DayOfWeek::Wednesday));

        $this->assertSame($expectedPastWednesdaysCount, $forecast->dataPointsCount);
        $this->assertGreaterThanOrEqual(2, $forecast->dataPointsCount);
    }

    public function test_forecast_ignores_sales_of_customers_not_in_the_beat(): void
    {
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Tuesday);
        $beatCustomer = $this->makeCustomer();
        $this->addTemplateBeatStop($beat, $beatCustomer);

        $unrelatedCustomer = $this->makeCustomer(); // NOT added to the beat

        $pastTuesdayDates = $this->findPastDatesMatchingDayOfWeek(DayOfWeek::Tuesday);
        $this->assertNotEmpty($pastTuesdayDates, 'Expected at least 1 Tuesday in past 15 days');

        $targetDate = $pastTuesdayDates[0];

        // Only the unrelated customer has sales on the past Tuesday
        $this->createInvoiceWithSaleForCustomerOnDate($unrelatedCustomer, $targetDate, 100_000);

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        // Beat customer had no sales — forecast must be 0 regardless of unrelated customer
        $this->assertSame(0, $forecast->forecastedTotalSales);
        $this->assertSame(0, $forecast->forecastedTotalProfit);
    }

    public function test_forecast_ignores_sales_on_days_not_matching_the_beat_day_of_week(): void
    {
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Friday);
        $customer = $this->makeCustomer();
        $this->addTemplateBeatStop($beat, $customer);

        // Create sales on a Thursday (the wrong day) instead of Friday
        $pastThursdayDates = $this->findPastDatesMatchingDayOfWeek(DayOfWeek::Thursday);
        if (empty($pastThursdayDates)) {
            $this->markTestSkipped('No Thursday found in the lookback window.');
        }

        $this->createInvoiceWithSaleForCustomerOnDate($customer, $pastThursdayDates[0], 80_000);

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        // Only Fridays are counted — Thursday sales must not be included
        $this->assertSame(0, $forecast->forecastedTotalSales);
    }

    public function test_forecast_aggregates_sales_of_multiple_beat_customers_on_the_same_date(): void
    {
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Thursday);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $this->addTemplateBeatStop($beat, $customerA);
        $this->addTemplateBeatStop($beat, $customerB);

        $pastThursdayDates = $this->findPastDatesMatchingDayOfWeek(DayOfWeek::Thursday);
        $this->assertNotEmpty($pastThursdayDates, 'Expected at least 1 Thursday in past 15 days');

        $targetDate = $pastThursdayDates[0];

        $this->createInvoiceWithSaleForCustomerOnDate($customerA, $targetDate, 30_000);
        $this->createInvoiceWithSaleForCustomerOnDate($customerB, $targetDate, 20_000);

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        // Both customers' sales on the beat day are summed per data point, then averaged
        $combinedSalesOnTargetDate = 50_000;
        $expectedForecastedSales = (int) round($combinedSalesOnTargetDate / $forecast->dataPointsCount);

        $this->assertSame($expectedForecastedSales, $forecast->forecastedTotalSales);
    }

    public function test_forecast_profit_is_correctly_derived_from_vente_profit_column(): void
    {
        $productWithKnownMargin = $this->makeProduct(price: 10_000, costPrice: 6_000);
        $beat = $this->makeBeatForDayOfWeek(DayOfWeek::Saturday);
        $customer = $this->makeCustomer();
        $this->addTemplateBeatStop($beat, $customer);

        $pastSaturdayDates = $this->findPastDatesMatchingDayOfWeek(DayOfWeek::Saturday);
        $this->assertNotEmpty($pastSaturdayDates, 'Expected at least 1 Saturday in past 15 days');

        $targetDate = $pastSaturdayDates[0];
        $price = 10_000;
        $quantity = 3;
        $totalProfit = ($price - 6_000) * $quantity; // 12_000

        $this->createInvoiceWithSaleForCustomerOnDate(
            $customer,
            $targetDate,
            $price,
            $quantity,
            $productWithKnownMargin,
            $totalProfit,
        );

        $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

        $expectedForecastedProfit = (int) round($totalProfit / $forecast->dataPointsCount);

        $this->assertSame($expectedForecastedProfit, $forecast->forecastedTotalProfit);
    }
}
