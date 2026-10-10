<?php

namespace Tests\Feature\Customer;

use App\Data\Customer\CustomerActivityFilter;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Ligne;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Sector;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Models\Zone;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the "Activités Clients" PDF export: which customers are listed,
 * the debt / last active / total paid / last commercial figures, and the title.
 */
class CustomerActivityExportTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $customerService;

    private Product $defaultProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerService = app(CustomerService::class);
        $this->defaultProduct = Product::create([
            'name' => 'Product '.uniqid(),
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);
    }

    private function makeCommercial(string $name): Commercial
    {
        $team = Team::create(['name' => 'Team '.uniqid(), 'user_id' => User::factory()->create()->id]);
        $commercial = Commercial::create([
            'name' => $name,
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();

        return $commercial;
    }

    private function makeCustomer(string $name, ?Commercial $commercial = null, ?int $sectorId = null): Customer
    {
        $customer = Customer::create([
            'name' => $name,
            'address' => 'Adresse '.$name,
            'owner_number' => '221'.rand(700000000, 799999999),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => ($commercial ?? $this->makeCommercial('Créateur '.uniqid()))->id,
        ]);
        $customer->forceFill(['sector_id' => $sectorId])->save();

        return $customer;
    }

    private function makeInvoice(Customer $customer, ?Commercial $commercial, int $totalAmount, int $paidAmount, string $createdAt): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial?->id,
        ]);
        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'price' => $totalAmount,
            'quantity' => 1,
            'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);
        if ($paidAmount > 0) {
            Payment::create([
                'sales_invoice_id' => $invoice->id,
                'amount' => $paidAmount,
                'payment_method' => 'CASH',
                'user_id' => User::factory()->create()->id,
            ]);
        }
        SalesInvoice::query()->whereKey($invoice->id)->update(['created_at' => $createdAt]);

        return $invoice;
    }

    private function filterWithInvoices(string $startDate, string $endDate): CustomerActivityFilter
    {
        return new CustomerActivityFilter(
            filterType: CustomerActivityFilter::TYPE_WITH_INVOICE,
            startDate: $startDate,
            endDate: $endDate,
        );
    }

    public function test_debt_is_summed_across_all_invoices_of_the_customer_not_only_the_date_range(): void
    {
        $customer = $this->makeCustomer('Resto A');
        $this->makeInvoice($customer, null, 10000, 4000, '2026-01-10 10:00:00');
        $this->makeInvoice($customer, null, 5000, 0, '2026-06-10 10:00:00');
        $this->makeInvoice($customer, null, 2000, 2000, '2026-06-15 10:00:00');

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertCount(1, $export->rows);
        $this->assertSame(11000, $export->rows->first()->totalDebtAmount);
        $this->assertSame(6000, $export->rows->first()->totalPaidAmount);
    }

    public function test_debt_is_zero_when_all_invoices_are_fully_paid(): void
    {
        $customer = $this->makeCustomer('Resto Payé');
        $this->makeInvoice($customer, null, 7000, 7000, '2026-06-10 10:00:00');

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertSame(0, $export->rows->first()->totalDebtAmount);
        $this->assertSame(7000, $export->rows->first()->totalPaidAmount);
    }

    public function test_last_active_date_is_the_most_recent_invoice_date(): void
    {
        $customer = $this->makeCustomer('Resto A');
        $this->makeInvoice($customer, null, 1000, 1000, '2026-06-02 08:00:00');
        $this->makeInvoice($customer, null, 1000, 1000, '2026-06-20 08:00:00');
        $this->makeInvoice($customer, null, 1000, 1000, '2026-06-11 08:00:00');

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertSame('2026-06-20', $export->rows->first()->lastActiveAt->toDateString());
    }

    public function test_last_serving_commercial_is_the_commercial_of_the_most_recent_invoice(): void
    {
        $olderCommercial = $this->makeCommercial('Ancien Commercial');
        $latestCommercial = $this->makeCommercial('Dernier Commercial');
        $customer = $this->makeCustomer('Resto A');
        $this->makeInvoice($customer, $latestCommercial, 1000, 0, '2026-06-20 08:00:00');
        $this->makeInvoice($customer, $olderCommercial, 1000, 0, '2026-06-05 08:00:00');

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertSame('Dernier Commercial', $export->rows->first()->lastServingCommercialName);
    }

    public function test_each_customer_gets_their_own_totals_when_several_are_exported(): void
    {
        $firstCustomer = $this->makeCustomer('Resto 1');
        $secondCustomer = $this->makeCustomer('Resto 2');
        $this->makeInvoice($firstCustomer, null, 3000, 1000, '2026-06-10 10:00:00');
        $this->makeInvoice($secondCustomer, null, 9000, 9000, '2026-06-12 10:00:00');
        $this->makeInvoice($secondCustomer, null, 500, 0, '2026-06-13 10:00:00');

        $rowsByName = $this->customerService
            ->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'))
            ->rows->keyBy('name');

        $this->assertSame(2000, $rowsByName['Resto 1']->totalDebtAmount);
        $this->assertSame(1000, $rowsByName['Resto 1']->totalPaidAmount);
        $this->assertSame(500, $rowsByName['Resto 2']->totalDebtAmount);
        $this->assertSame(9000, $rowsByName['Resto 2']->totalPaidAmount);
    }

    public function test_overpaid_invoice_does_not_break_the_export_and_offsets_other_debt(): void
    {
        $customer = $this->makeCustomer('Resto A');
        $overpaidInvoice = $this->makeInvoice($customer, null, 1000, 0, '2026-06-10 10:00:00');
        $this->makeInvoice($customer, null, 5000, 0, '2026-06-11 10:00:00');
        SalesInvoice::query()->whereKey($overpaidInvoice->id)->update(['total_payments' => 1500]);

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertSame(4500, $export->rows->first()->totalDebtAmount);
    }

    private function makeBeat(string $name, DayOfWeek $dayOfWeek, Commercial $commercial): Beat
    {
        return Beat::create(['name' => $name, 'day_of_week' => $dayOfWeek, 'commercial_id' => $commercial->id]);
    }

    private function addCustomerToBeat(Customer $customer, Beat $beat): void
    {
        BeatStop::create(['customer_id' => $customer->id, 'beat_id' => $beat->id]);
    }

    private function makeInvoicedCustomer(string $name, string $gpsCoordinates = '14.6928,17.4467'): Customer
    {
        $customer = $this->makeCustomer($name);
        $customer->forceFill(['gps_coordinates' => $gpsCoordinates])->save();
        $this->makeInvoice($customer, null, 1000, 0, '2026-06-10 10:00:00');

        return $customer;
    }

    public function test_customers_are_ordered_by_weekday_of_beat_then_gps_then_name_with_no_beat_last(): void
    {
        $commercial = $this->makeCommercial('Awa');
        $mondayBeat = $this->makeBeat('Beat Lundi', DayOfWeek::Monday, $commercial);
        $tuesdayBeat = $this->makeBeat('Beat Mardi', DayOfWeek::Tuesday, $commercial);
        $fridayBeat = $this->makeBeat('Beat Vendredi', DayOfWeek::Friday, $commercial);

        $noBeat = $this->makeInvoicedCustomer('Aaa sans beat', '10.0,10.0');
        $friday = $this->makeInvoicedCustomer('Zed vendredi', '10.0,10.0');
        $tuesdayZ = $this->makeInvoicedCustomer('Zoe mardi', '14.0,17.0');
        $tuesdayA = $this->makeInvoicedCustomer('Abel mardi', '14.0,17.0');
        $tuesdayFarGps = $this->makeInvoicedCustomer('Aaa mardi gps haut', '15.0,17.0');
        $monday = $this->makeInvoicedCustomer('Zack lundi', '99.0,99.0');
        $this->addCustomerToBeat($friday, $fridayBeat);
        $this->addCustomerToBeat($tuesdayZ, $tuesdayBeat);
        $this->addCustomerToBeat($tuesdayA, $tuesdayBeat);
        $this->addCustomerToBeat($tuesdayFarGps, $tuesdayBeat);
        $this->addCustomerToBeat($monday, $mondayBeat);

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertSame(
            ['Zack lundi', 'Abel mardi', 'Zoe mardi', 'Aaa mardi gps haut', 'Zed vendredi', 'Aaa sans beat'],
            $export->rows->pluck('name')->all()
        );
    }

    public function test_beat_label_shows_beat_name_and_day_or_null_when_customer_has_no_beat(): void
    {
        $commercial = $this->makeCommercial('Awa');
        $wednesdayBeat = $this->makeBeat('Beat Almadies', DayOfWeek::Wednesday, $commercial);
        $mondayBeat = $this->makeBeat('Beat Ouakam', DayOfWeek::Monday, $commercial);
        $customerInTwoBeats = $this->makeInvoicedCustomer('Deux beats');
        $customerWithoutBeat = $this->makeInvoicedCustomer('Sans beat');
        $this->addCustomerToBeat($customerInTwoBeats, $wednesdayBeat);
        $this->addCustomerToBeat($customerInTwoBeats, $mondayBeat);

        $rowsByName = $this->customerService
            ->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'))
            ->rows->keyBy('name');

        $this->assertSame('Beat Ouakam (Lundi), Beat Almadies (Mercredi)', $rowsByName['Deux beats']->beatLabel);
        $this->assertNull($rowsByName['Sans beat']->beatLabel);
        $html = view('pdf.customer-activity-list', [
            'export' => $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30')),
            'date' => 'now',
        ])->render();
        $this->assertStringContainsString('Pas de beat', $html);
    }

    public function test_beat_round_stops_are_ignored_for_the_beat_label(): void
    {
        $commercial = $this->makeCommercial('Awa');
        $beat = $this->makeBeat('Beat Lundi', DayOfWeek::Monday, $commercial);
        $customer = $this->makeInvoicedCustomer('Resto');
        $round = BeatRound::create(['name' => 'Tournée', 'beat_id' => $beat->id, 'commercial_id' => $commercial->id, 'planned_at' => '2026-06-08']);
        BeatStop::create(['customer_id' => $customer->id, 'beat_id' => $beat->id, 'beat_round_id' => $round->id]);

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertNull($export->rows->first()->beatLabel);
    }

    public function test_written_off_invoices_are_not_counted_in_debt(): void
    {
        $customer = $this->makeCustomer('Resto A');
        $this->makeInvoice($customer, null, 1000, 0, '2026-06-10 10:00:00');
        $lostInvoice = $this->makeInvoice($customer, null, 50000, 0, '2026-06-11 10:00:00');
        SalesInvoice::withoutGlobalScopes()->whereKey($lostInvoice->id)->update(['written_off_at' => now()]);

        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertSame(1000, $export->rows->first()->totalDebtAmount);
    }

    public function test_export_is_empty_when_no_customer_matches(): void
    {
        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertCount(0, $export->rows);
    }

    public function test_sector_filter_only_exports_customers_of_that_sector_and_names_it_in_the_title(): void
    {
        $ligne = Ligne::create(['name' => 'Ligne Test', 'zone_id' => Zone::create(['name' => 'Zone Test', 'gps_coordinates' => '14.6928,17.4467', 'ville' => 'Dakar', 'quartiers' => 'Plateau'])->id]);
        $sector = Sector::create(['name' => 'Almadies', 'ligne_id' => $ligne->id]);
        $otherSector = Sector::create(['name' => 'Pikine', 'ligne_id' => $ligne->id]);
        $customerInSector = $this->makeCustomer('Dans secteur', null, $sector->id);
        $customerInOtherSector = $this->makeCustomer('Autre secteur', null, $otherSector->id);
        $this->makeInvoice($customerInSector, null, 1000, 0, '2026-06-10 10:00:00');
        $this->makeInvoice($customerInOtherSector, null, 1000, 0, '2026-06-10 10:00:00');

        $export = $this->customerService->buildCustomerActivityExport(new CustomerActivityFilter(
            filterType: CustomerActivityFilter::TYPE_BY_SECTOR,
            startDate: '2026-06-01',
            endDate: '2026-06-30',
            sectorId: $sector->id,
        ));

        $this->assertSame(['Dans secteur'], $export->rows->pluck('name')->all());
        $this->assertSame('Liste des clients du secteur Almadies ayant une facture du 01/06/2026 au 30/06/2026', $export->title);
        $this->assertFalse($export->includesChurningColumns);
    }

    public function test_default_filter_title_mentions_the_period(): void
    {
        $export = $this->customerService->buildCustomerActivityExport($this->filterWithInvoices('2026-06-01', '2026-06-30'));

        $this->assertSame('Liste des clients ayant une facture du 01/06/2026 au 30/06/2026', $export->title);
        $this->assertNull($export->subtitle);
    }

    public function test_above_amount_filter_title_mentions_the_threshold(): void
    {
        $bigCustomer = $this->makeCustomer('Gros');
        $smallCustomer = $this->makeCustomer('Petit');
        $this->makeInvoice($bigCustomer, null, 150000, 0, '2026-06-10 10:00:00');
        $this->makeInvoice($smallCustomer, null, 1000, 0, '2026-06-10 10:00:00');

        $export = $this->customerService->buildCustomerActivityExport(new CustomerActivityFilter(
            filterType: CustomerActivityFilter::TYPE_ABOVE_AMOUNT,
            startDate: '2026-06-01',
            endDate: '2026-06-30',
            minimumAmount: 100000,
        ));

        $this->assertSame(['Gros'], $export->rows->pluck('name')->all());
        $this->assertStringContainsString('100 000 F', $export->title);
    }

    public function test_churning_export_title_columns_and_figures(): void
    {
        $commercial = $this->makeCommercial('Awa');
        $churningCustomer = $this->makeCustomer('Resto parti');
        $this->makeInvoice($churningCustomer, $commercial, 4000, 4000, now()->subDays(80)->toDateTimeString());
        $this->makeInvoice($churningCustomer, $commercial, 6000, 1000, now()->subDays(70)->toDateTimeString());
        $this->makeInvoice($churningCustomer, $commercial, 2000, 2000, now()->subDays(60)->toDateTimeString());

        $export = $this->customerService->buildCustomerActivityExport(new CustomerActivityFilter(
            filterType: CustomerActivityFilter::TYPE_CHURNING,
            startDate: now()->subDays(90)->toDateString(),
            endDate: now()->toDateString(),
            inactiveDays: 30,
        ));

        $this->assertSame('Liste des clients inactifs depuis 30 jours', $export->title);
        $this->assertNotNull($export->subtitle);
        $this->assertTrue($export->includesChurningColumns);
        $this->assertCount(1, $export->rows);
        $row = $export->rows->first();
        $this->assertSame(5000, $row->totalDebtAmount);
        $this->assertSame(7000, $row->totalPaidAmount);
        $this->assertSame('Awa', $row->lastServingCommercialName);
        $this->assertSame(now()->subDays(60)->toDateString(), $row->lastActiveAt->toDateString());
    }

    public function test_churning_filter_without_inactive_days_exports_nothing(): void
    {
        $customer = $this->makeCustomer('Resto');
        $this->makeInvoice($customer, null, 1000, 0, '2026-06-10 10:00:00');

        $export = $this->customerService->buildCustomerActivityExport(new CustomerActivityFilter(
            filterType: CustomerActivityFilter::TYPE_CHURNING,
            startDate: '2026-06-01',
            endDate: '2026-06-30',
        ));

        $this->assertCount(0, $export->rows);
    }

    public function test_export_endpoint_returns_a_pdf_for_an_authenticated_user(): void
    {
        $customer = $this->makeCustomer('Resto A');
        $this->makeInvoice($customer, null, 1000, 0, '2026-06-10 10:00:00');

        $response = $this->actingAs(User::factory()->create())->get(route('clients.activity-map.export-pdf', [
            'filter_type' => 'with_invoice',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_view_shows_churning_columns_only_for_churning_exports(): void
    {
        $commercial = $this->makeCommercial('Awa Diop');
        $customer = $this->makeCustomer('Resto parti', $commercial);
        $this->makeInvoice($customer, $commercial, 4000, 1000, now()->subDays(80)->toDateTimeString());
        $this->makeInvoice($customer, $commercial, 4000, 4000, now()->subDays(70)->toDateTimeString());
        $this->makeInvoice($customer, $commercial, 4000, 4000, now()->subDays(60)->toDateTimeString());

        $churningExport = $this->customerService->buildCustomerActivityExport(new CustomerActivityFilter(
            filterType: CustomerActivityFilter::TYPE_CHURNING,
            startDate: now()->subDays(90)->toDateString(),
            endDate: now()->toDateString(),
            inactiveDays: 30,
        ));
        $regularExport = $this->customerService->buildCustomerActivityExport(
            $this->filterWithInvoices(now()->subDays(90)->toDateString(), now()->toDateString())
        );

        $churningHtml = view('pdf.customer-activity-list', ['export' => $churningExport, 'date' => 'now'])->render();
        $regularHtml = view('pdf.customer-activity-list', ['export' => $regularExport, 'date' => 'now'])->render();

        $this->assertStringContainsString('Liste des clients inactifs depuis 30 jours', $churningHtml);
        $this->assertStringContainsString('Total payé', $churningHtml);
        $this->assertStringContainsString('Dernier commercial', $churningHtml);
        $this->assertStringContainsString('Awa Diop', $churningHtml);
        $this->assertStringContainsString('9 000 F', $churningHtml);
        $this->assertStringContainsString('3 000 F', $churningHtml);
        $this->assertStringNotContainsString('Total payé', $regularHtml);
        $this->assertStringNotContainsString('Dernier commercial', $regularHtml);
    }

    public function test_export_endpoint_rejects_guests(): void
    {
        $this->get(route('clients.activity-map.export-pdf'))->assertRedirect(route('login'));
    }

    public function test_activity_map_page_still_lists_the_same_customers(): void
    {
        $customer = $this->makeCustomer('Resto A');
        $this->makeInvoice($customer, null, 1000, 0, '2026-06-10 10:00:00');

        $response = $this->actingAs(User::factory()->create())->get(route('clients.activity-map', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Clients/Activity')
            ->has('customers', 1)
            ->where('filters.filter_type', 'with_invoice'));
    }
}
