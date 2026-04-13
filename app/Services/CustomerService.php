<?php

namespace App\Services;

use App\Models\Commercial;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerService
{
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
     * Create a new customer assigned to the given commercial.
     */
    public function createCustomer(Commercial $commercial, array $validatedData): Customer
    {
        return $commercial->customers()->create($validatedData);
    }

    /**
     * Update an existing customer with the given data.
     */
    public function updateCustomer(Customer $customer, array $validatedData): Customer
    {
        $customer->update($validatedData);

        return $customer->fresh();
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
