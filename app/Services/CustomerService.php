<?php

namespace App\Services;

use App\Models\Commercial;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

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
