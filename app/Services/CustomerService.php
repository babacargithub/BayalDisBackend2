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
