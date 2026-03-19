<?php

namespace App\Data\Geographic;

/**
 * Represents a single customer's aggregated activity within a sector.
 *
 * Used to display top customers by invoice frequency and by revenue volume.
 * A customer is considered recurring when they have 2 or more invoices in
 * the requested period — this is a key churn/retention indicator.
 */
readonly class TopCustomerDTO
{
    public function __construct(
        public int $customerId,
        public string $customerName,

        /** Number of invoices issued to this customer in the period. */
        public int $invoicesCount,

        /** Total revenue from this customer: SUM(total_amount) across all their invoices. */
        public int $totalSales,

        /** True when invoicesCount >= 2 — the customer bought more than once. */
        public bool $isRecurring,
    ) {}

    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'customer_name' => $this->customerName,
            'invoices_count' => $this->invoicesCount,
            'total_sales' => $this->totalSales,
            'is_recurring' => $this->isRecurring,
        ];
    }
}
