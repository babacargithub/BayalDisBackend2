<?php

namespace App\Services;

use App\Data\Customer\CustomerActivityExportDTO;
use App\Data\Customer\CustomerActivityExportRowDTO;
use App\Data\Customer\CustomerActivityFilter;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\Sector;
use App\Repositories\CustomerRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

class CustomerService
{
    public function __construct(private readonly CustomerRepository $customerRepository) {}

    /**
     * Return all customers visible to the given commercial, served from cache.
     */
    public function getCustomersForCommercial(Commercial $commercial): Collection
    {
        return $this->customerRepository->getAllForCommercial($commercial);
    }

    /**
     * Returns a base query of customers visible to the given commercial.
     *
     * Visibility rule: all customers created by any commercial that belongs
     * to the same team as the current commercial. If the commercial has no
     * team, only their own customers are returned.
     */
    public function getCustomersQueryForCommercial(Commercial $commercial): Builder
    {
        return Customer::query()
            ->when(
                $commercial->team_id !== null,
                fn (Builder $query) => $query->whereHas(
                    'commercial',
                    fn (Builder $subQuery) => $subQuery->where('team_id', $commercial->team_id)
                ),
                fn (Builder $query) => $query->where('commercial_id', $commercial->id)
            )
            ->latest();
    }

    /**
     * Count the customers created today that are visible to the commercial.
     */
    public function getTodayCustomersCount(Commercial $commercial): int
    {
        return $this->getCustomersQueryForCommercial($commercial)
            ->whereDate('customers.created_at', today())
            ->count();
    }

    /**
     * Create a new customer assigned to the given commercial (mobile API path).
     */
    public function createCustomer(Commercial $commercial, array $validatedData): Customer
    {
        $customer = $commercial->customers()->create($validatedData);
        $this->customerRepository->invalidateAllCaches();

        return $customer;
    }

    /**
     * Create a customer with tag associations (back-office path).
     *
     * @param  int[]  $tagIds
     */
    public function storeCustomerWithTags(array $validatedData, array $tagIds): Customer
    {
        $customer = Customer::create($validatedData);
        $customer->tags()->sync($tagIds);
        $this->customerRepository->invalidateAllCaches();

        return $customer;
    }

    /**
     * Update an existing customer with the given data (mobile API path).
     */
    public function updateCustomer(Customer $customer, array $validatedData): Customer
    {
        $customer->update($validatedData);
        $this->customerRepository->invalidateAllCaches();

        return $customer->fresh();
    }

    /**
     * Update a customer with tag associations (back-office path).
     *
     * @param  int[]  $tagIds
     */
    public function updateCustomerWithTags(Customer $customer, array $validatedData, array $tagIds): Customer
    {
        $customer->update($validatedData);
        $customer->tags()->sync($tagIds);
        $this->customerRepository->invalidateAllCaches();

        return $customer->fresh();
    }

    /**
     * Delete a customer and invalidate the customer cache.
     */
    public function deleteCustomer(Customer $customer): void
    {
        $customer->delete();
        $this->customerRepository->invalidateAllCaches();
    }

    /**
     * Check whether a commercial can read/access a customer.
     *
     * Read access is granted at the team level: any commercial in the same
     * team can access any customer created by a teammate. Without a team,
     * only the creator commercial can access the customer.
     */
    public function canAccessCustomer(Commercial $commercial, Customer $customer): bool
    {
        if ($commercial->team_id === null) {
            return $customer->commercial_id === $commercial->id;
        }

        $customer->loadMissing('commercial');

        return $customer->commercial?->team_id === $commercial->team_id;
    }

    /**
     * Return the top 50 customers ranked by the given sort criterion.
     *
     * Supported sort values:
     *   - 'volume'    — ordered by total invoice amount (default)
     *   - 'frequency' — ordered by number of invoices
     *
     * Only customers who have at least one sales invoice are included.
     * Aggregated totals come from the denormalized stored columns on
     * sales_invoices to avoid expensive per-row subqueries.
     *
     * @return Collection<int, object{id: int, name: string, phone_number: string, address: string|null, invoices_count: int, volume: int, total_payment: int, total_realized_profit: int}>
     */
    public function getTopCustomers(string $sortBy = 'volume'): Collection
    {
        $orderByColumn = $sortBy === 'frequency' ? 'invoices_count' : 'volume';

        return Customer::query()
            ->select('customers.id', 'customers.name', 'customers.phone_number', 'customers.address')
            ->join('sales_invoices', 'sales_invoices.customer_id', '=', 'customers.id')
            ->selectRaw('COUNT(sales_invoices.id) as invoices_count')
            ->selectRaw('COALESCE(SUM(sales_invoices.total_amount), 0) as volume')
            ->selectRaw('COALESCE(SUM(sales_invoices.total_payments), 0) as total_payment')
            ->selectRaw('COALESCE(SUM(sales_invoices.total_realized_profit), 0) as total_realized_profit')
            ->groupBy('customers.id', 'customers.name', 'customers.phone_number', 'customers.address')
            ->orderByDesc($orderByColumn)
            ->limit(50)
            ->get();
    }

    /**
     * Base query builder for the customer activity map: joins sales_invoices,
     * restricts to customers with GPS coordinates and invoices within the
     * given date range, and selects the aggregated invoice totals.
     *
     * All activity-map filter methods build on top of this shared base.
     */
    private function buildActivityMapBaseQuery(string $startDate, string $endDate): \Illuminate\Database\Eloquent\Builder
    {
        return Customer::query()
            ->select('customers.id', 'customers.name', 'customers.phone_number', 'customers.address', 'customers.gps_coordinates')
            ->join('sales_invoices', 'sales_invoices.customer_id', '=', 'customers.id')
            ->whereNotNull('customers.gps_coordinates')
            ->whereDate('sales_invoices.created_at', '>=', $startDate)
            ->whereDate('sales_invoices.created_at', '<=', $endDate)
            ->selectRaw('COUNT(sales_invoices.id) as invoices_count')
            ->selectRaw('COALESCE(SUM(sales_invoices.total_amount), 0) as total_invoice_amount')
            ->groupBy('customers.id', 'customers.name', 'customers.phone_number', 'customers.address', 'customers.gps_coordinates')
            ->orderByDesc('total_invoice_amount');
    }

    /**
     * Return all customers who had at least one sales invoice in the date range.
     *
     * @return Collection<int, object{id: int, name: string, phone_number: string, address: string|null, gps_coordinates: string, invoices_count: int, total_invoice_amount: int}>
     */
    public function getCustomersWithInvoicesInDateRange(string $startDate, string $endDate): Collection
    {
        return $this->buildActivityMapBaseQuery($startDate, $endDate)->get();
    }

    /**
     * Return customers who belong to the given sector and had at least one
     * sales invoice in the date range.
     *
     * @return Collection<int, object{id: int, name: string, phone_number: string, address: string|null, gps_coordinates: string, invoices_count: int, total_invoice_amount: int}>
     */
    public function getCustomersInSectorWithInvoicesInDateRange(int $sectorId, string $startDate, string $endDate): Collection
    {
        return $this->buildActivityMapBaseQuery($startDate, $endDate)
            ->where('customers.sector_id', $sectorId)
            ->get();
    }

    /**
     * Return customers whose total invoice amount within the date range
     * exceeds the given minimum amount.
     *
     * @return Collection<int, object{id: int, name: string, phone_number: string, address: string|null, gps_coordinates: string, invoices_count: int, total_invoice_amount: int}>
     */
    public function getCustomersWithInvoicesAboveAmountInDateRange(int $minimumAmount, string $startDate, string $endDate): Collection
    {
        return $this->buildActivityMapBaseQuery($startDate, $endDate)
            ->having('total_invoice_amount', '>=', $minimumAmount)
            ->get();
    }

    /**
     * Return customers whose average invoice amount within the date range
     * exceeds the given minimum average amount.
     *
     * @return Collection<int, object{id: int, name: string, phone_number: string, address: string|null, gps_coordinates: string, invoices_count: int, total_invoice_amount: int, average_invoice_amount: float}>
     */
    public function getCustomersWithAverageInvoiceAboveAmountInDateRange(int $minimumAverageAmount, string $startDate, string $endDate): Collection
    {
        return $this->buildActivityMapBaseQuery($startDate, $endDate)
            ->selectRaw('AVG(sales_invoices.total_amount) as average_invoice_amount')
            ->having('average_invoice_amount', '>=', $minimumAverageAmount)
            ->get();
    }

    /**
     * Return every customer who has at least one invoice, with their GPS
     * coordinates and individual financial performance metrics.
     *
     * Used to power the area analysis map: each customer is plotted as a
     * circle whose radius and colour reflect their avg invoice and avg profit.
     *
     * @return Collection<int, object{id: int, name: string, gps_coordinates: string, sector_id: int|null, total_invoices: int, avg_invoice_amount: float, avg_profit: float}>
     */
    public function getCustomersWithFinancialMetricsForAreaAnalysis(): Collection
    {
        return Customer::query()
            ->select('customers.id', 'customers.name', 'customers.gps_coordinates', 'customers.sector_id', 'customers.is_prospect')
            ->join('sales_invoices', 'sales_invoices.customer_id', '=', 'customers.id')
            ->whereNotNull('customers.gps_coordinates')
            ->selectRaw('COUNT(DISTINCT sales_invoices.id) as total_invoices')
            ->selectRaw('ROUND(AVG(sales_invoices.total_amount)) as avg_invoice_amount')
            ->selectRaw('ROUND(AVG(sales_invoices.total_realized_profit)) as avg_profit')
            ->groupBy('customers.id', 'customers.name', 'customers.gps_coordinates', 'customers.sector_id', 'customers.is_prospect')
            ->get();
    }

    /**
     * Return sector-level financial metrics and an opportunity score used to
     * recommend expansion areas for new customer acquisition.
     *
     * Opportunity score = avg_invoice × (1 - penetration_rate)
     * where penetration_rate = customers_with_invoices / total_customers_in_sector.
     *
     * A high score means the sector has high-value buyers AND a large pool of
     * customers who have not yet converted — the ideal target for new sales.
     *
     * @return Collection<int, object{id: int, name: string, total_customers: int, customers_with_invoices: int, penetration_rate: float, avg_invoice_amount: float, avg_profit: float, total_revenue: float, opportunity_score: float, recommendation: string}>
     */
    public function getSectorFinancialMetricsForAreaAnalysis(): Collection
    {
        $sectorMetrics = \App\Models\Sector::query()
            ->select('sectors.id', 'sectors.name')
            ->selectRaw('COUNT(DISTINCT customers.id) as total_customers')
            ->selectRaw('COUNT(DISTINCT CASE WHEN sales_invoices.id IS NOT NULL THEN customers.id END) as customers_with_invoices')
            ->selectRaw('COALESCE(ROUND(AVG(sales_invoices.total_amount)), 0) as avg_invoice_amount')
            ->selectRaw('COALESCE(ROUND(AVG(sales_invoices.total_realized_profit)), 0) as avg_profit')
            ->selectRaw('COALESCE(SUM(sales_invoices.total_amount), 0) as total_revenue')
            ->leftJoin('customers', 'customers.sector_id', '=', 'sectors.id')
            ->leftJoin('sales_invoices', 'sales_invoices.customer_id', '=', 'customers.id')
            ->groupBy('sectors.id', 'sectors.name')
            ->having('total_customers', '>', 0)
            ->get()
            ->map(function ($sector) {
                $penetrationRate = $sector->total_customers > 0
                    ? $sector->customers_with_invoices / $sector->total_customers
                    : 0;

                $opportunityScore = (int) round($sector->avg_invoice_amount * (1 - $penetrationRate));

                $recommendation = match (true) {
                    $opportunityScore >= 5000 => 'Priorité haute',
                    $opportunityScore >= 2500 => 'Priorité moyenne',
                    default => 'Priorité basse',
                };

                return (object) [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'total_customers' => $sector->total_customers,
                    'customers_with_invoices' => $sector->customers_with_invoices,
                    'penetration_rate' => round($penetrationRate * 100, 1),
                    'avg_invoice_amount' => (int) $sector->avg_invoice_amount,
                    'avg_profit' => (int) $sector->avg_profit,
                    'total_revenue' => (int) $sector->total_revenue,
                    'opportunity_score' => $opportunityScore,
                    'recommendation' => $recommendation,
                ];
            })
            ->sortByDesc('opportunity_score')
            ->values();

        return $sectorMetrics;
    }

    /**
     * Return customers who are churning: they were active in the given date
     * range, have more than 2 invoices in total, but have not placed any
     * invoice in the last $inactiveDaysThreshold days.
     *
     * The date range identifies the "was active" window; the inactive days
     * threshold identifies "has gone silent since".
     *
     * @return Collection<int, object{id: int, name: string, phone_number: string, address: string|null, gps_coordinates: string, total_invoices_count: int, last_invoice_date: string}>
     */
    public function getChurningCustomersInDateRange(int $inactiveDaysThreshold, string $startDate, string $endDate): Collection
    {
        $silenceCutoffDate = now()->subDays($inactiveDaysThreshold)->toDateString();

        return Customer::query()
            ->select('customers.id', 'customers.name', 'customers.phone_number', 'customers.address', 'customers.gps_coordinates')
            ->whereNotNull('customers.gps_coordinates')
            // They had at least one invoice in the selected date range (were active)
            ->whereHas('salesInvoices', function ($query) use ($startDate, $endDate): void {
                $query->whereDate('created_at', '>=', $startDate)
                    ->whereDate('created_at', '<=', $endDate);
            })
            // They have more than 2 invoices in total (excludes brand-new customers)
            ->has('salesInvoices', '>', 2)
            // They have not placed any invoice since the silence cutoff date
            ->whereDoesntHave('salesInvoices', function ($query) use ($silenceCutoffDate): void {
                $query->whereDate('created_at', '>=', $silenceCutoffDate);
            })
            ->selectRaw('(SELECT COUNT(*) FROM sales_invoices WHERE sales_invoices.customer_id = customers.id) as total_invoices_count')
            ->selectRaw('(SELECT MAX(created_at) FROM sales_invoices WHERE sales_invoices.customer_id = customers.id) as last_invoice_date')
            ->orderBy('last_invoice_date')
            ->get();
    }

    /**
     * Resolve the customers matching the activity-map criteria. Filters whose
     * required parameter is missing (e.g. sector filter without a sector)
     * match nothing.
     *
     * @return SupportCollection<int, Customer>
     */
    public function getCustomersMatchingActivityFilter(CustomerActivityFilter $filter): SupportCollection
    {
        return match ($filter->filterType) {
            CustomerActivityFilter::TYPE_BY_SECTOR => $filter->sectorId
                ? $this->getCustomersInSectorWithInvoicesInDateRange($filter->sectorId, $filter->startDate, $filter->endDate)
                : collect(),
            CustomerActivityFilter::TYPE_ABOVE_AMOUNT => $filter->minimumAmount
                ? $this->getCustomersWithInvoicesAboveAmountInDateRange($filter->minimumAmount, $filter->startDate, $filter->endDate)
                : collect(),
            CustomerActivityFilter::TYPE_ABOVE_AVERAGE_AMOUNT => $filter->minimumAverageAmount
                ? $this->getCustomersWithAverageInvoiceAboveAmountInDateRange($filter->minimumAverageAmount, $filter->startDate, $filter->endDate)
                : collect(),
            CustomerActivityFilter::TYPE_CHURNING => $filter->inactiveDays
                ? $this->getChurningCustomersInDateRange($filter->inactiveDays, $filter->startDate, $filter->endDate)
                : collect(),
            default => $this->getCustomersWithInvoicesInDateRange($filter->startDate, $filter->endDate),
        };
    }

    /**
     * Build the printable customer list for the given activity-map criteria.
     *
     * Per customer (across ALL their invoices, not only the date range):
     * - total debt = SUM(total_amount) − SUM(total_payments), floored at zero
     *   (subtracted in PHP: the columns are unsigned in MySQL, so a per-row
     *   subtraction overflows on overpaid invoices)
     * - last active = date of the most recent invoice
     * - total paid = SUM(total_payments)
     * - last serving commercial = commercial of the most recent invoice
     */
    public function buildCustomerActivityExport(CustomerActivityFilter $filter): CustomerActivityExportDTO
    {
        $customers = $this->getCustomersMatchingActivityFilter($filter);
        $customerIds = $customers->pluck('id')->all();

        $invoiceTotalsByCustomerId = collect();
        $lastServingCommercialNameByCustomerId = collect();

        if ($customerIds !== []) {
            $invoiceTotalsByCustomerId = SalesInvoice::query()
                ->whereIn('customer_id', $customerIds)
                ->selectRaw('customer_id')
                ->selectRaw('SUM(total_amount) as total_invoiced_amount')
                ->selectRaw('SUM(total_payments) as total_paid_amount')
                ->selectRaw('MAX(created_at) as last_invoice_created_at')
                ->groupBy('customer_id')
                ->get()
                ->keyBy('customer_id');

            $lastServingCommercialNameByCustomerId = SalesInvoice::query()
                ->whereIn('customer_id', $customerIds)
                ->with('commercial:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['id', 'customer_id', 'commercial_id', 'created_at'])
                ->unique('customer_id')
                ->mapWithKeys(fn (SalesInvoice $invoice) => [$invoice->customer_id => $invoice->commercial?->name]);
        }

        $beatsByCustomerId = $this->getBeatsSortedByWeekdayGroupedByCustomerId($customerIds);

        $sortedCustomers = $customers->sort(function ($firstCustomer, $secondCustomer) use ($beatsByCustomerId): int {
            return $this->buildActivityExportSortKey($firstCustomer, $beatsByCustomerId)
                <=> $this->buildActivityExportSortKey($secondCustomer, $beatsByCustomerId);
        });

        $rows = $sortedCustomers->map(function ($customer) use ($invoiceTotalsByCustomerId, $lastServingCommercialNameByCustomerId, $beatsByCustomerId): CustomerActivityExportRowDTO {
            $invoiceTotals = $invoiceTotalsByCustomerId->get($customer->id);

            return new CustomerActivityExportRowDTO(
                customerId: $customer->id,
                name: $customer->name,
                address: $customer->address,
                phoneNumber: $customer->phone_number,
                beatLabel: $beatsByCustomerId->has($customer->id)
                    ? $beatsByCustomerId->get($customer->id)->map(fn (Beat $beat) => $this->formatBeatLabel($beat))->implode(', ')
                    : null,
                totalDebtAmount: max(0, (int) ($invoiceTotals->total_invoiced_amount ?? 0) - (int) ($invoiceTotals->total_paid_amount ?? 0)),
                lastActiveAt: ($invoiceTotals?->last_invoice_created_at)
                    ? Carbon::parse($invoiceTotals->last_invoice_created_at)
                    : null,
                totalPaidAmount: (int) ($invoiceTotals->total_paid_amount ?? 0),
                lastServingCommercialName: $lastServingCommercialNameByCustomerId->get($customer->id),
            );
        })->values();

        return new CustomerActivityExportDTO(
            title: $this->buildActivityExportTitle($filter),
            subtitle: $filter->isChurning()
                ? 'Clients actifs entre le '.$this->formatDateInFrench($filter->startDate).' et le '.$this->formatDateInFrench($filter->endDate)
                : null,
            includesChurningColumns: $filter->isChurning(),
            rows: $rows,
        );
    }

    /**
     * Beats (recurring template stops only) of each customer, ordered Monday
     * to Sunday then by beat name.
     *
     * @param  array<int, int>  $customerIds
     * @return SupportCollection<int, SupportCollection<int, Beat>>
     */
    private function getBeatsSortedByWeekdayGroupedByCustomerId(array $customerIds): SupportCollection
    {
        if ($customerIds === []) {
            return collect();
        }

        return BeatStop::query()
            ->whereIn('customer_id', $customerIds)
            ->whereNull('beat_round_id')
            ->with('beat:id,name,day_of_week')
            ->get(['id', 'customer_id', 'beat_id'])
            ->filter(fn (BeatStop $stop) => $stop->beat !== null)
            ->groupBy('customer_id')
            ->map(fn (SupportCollection $stops) => $stops
                ->map(fn (BeatStop $stop) => $stop->beat)
                ->unique('id')
                ->sortBy(fn (Beat $beat) => [$this->getWeekdayPosition($beat), $beat->name])
                ->values());
    }

    private function getWeekdayPosition(Beat $beat): int
    {
        return $beat->day_of_week === null
            ? count(DayOfWeek::cases())
            : array_search($beat->day_of_week, DayOfWeek::cases(), true);
    }

    private function formatBeatLabel(Beat $beat): string
    {
        return $beat->day_of_week ? $beat->name.' ('.$beat->day_of_week->label().')' : $beat->name;
    }

    /**
     * Sort key for the printable list: first beat (Monday→Sunday, customers
     * without a beat last), then GPS coordinates, then customer name.
     *
     * @param  SupportCollection<int, SupportCollection<int, Beat>>  $beatsByCustomerId
     * @return array{int, string, string, string}
     */
    private function buildActivityExportSortKey(mixed $customer, SupportCollection $beatsByCustomerId): array
    {
        $firstBeat = $beatsByCustomerId->get($customer->id)?->first();

        return [
            $firstBeat ? $this->getWeekdayPosition($firstBeat) : PHP_INT_MAX,
            $firstBeat?->name ?? '',
            (string) $customer->gps_coordinates,
            mb_strtolower((string) $customer->name),
        ];
    }

    private function buildActivityExportTitle(CustomerActivityFilter $filter): string
    {
        $period = 'du '.$this->formatDateInFrench($filter->startDate).' au '.$this->formatDateInFrench($filter->endDate);

        return match ($filter->filterType) {
            CustomerActivityFilter::TYPE_BY_SECTOR => 'Liste des clients du secteur '
                .(Sector::query()->whereKey($filter->sectorId)->value('name') ?? '—')
                .' ayant une facture '.$period,
            CustomerActivityFilter::TYPE_ABOVE_AMOUNT => 'Liste des clients avec un total facturé d\'au moins '
                .$this->formatAmountInFrench($filter->minimumAmount).' '.$period,
            CustomerActivityFilter::TYPE_ABOVE_AVERAGE_AMOUNT => 'Liste des clients avec une facture moyenne d\'au moins '
                .$this->formatAmountInFrench($filter->minimumAverageAmount).' '.$period,
            CustomerActivityFilter::TYPE_CHURNING => 'Liste des clients inactifs depuis '.(int) $filter->inactiveDays.' jours',
            default => 'Liste des clients ayant une facture '.$period,
        };
    }

    private function formatDateInFrench(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    private function formatAmountInFrench(?int $amount): string
    {
        return number_format((int) $amount, 0, ',', ' ').' F';
    }

    /**
     * Check whether a commercial can modify (update/delete) a customer.
     *
     * Write access is restricted to the commercial who originally created
     * the customer.
     */
    public function canModifyCustomer(Commercial $commercial, Customer $customer): bool
    {
        return $customer->commercial_id === $commercial->id;
    }
}
