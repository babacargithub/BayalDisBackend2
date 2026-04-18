<?php

namespace App\Data\Vente;

class VenteStatsFilter
{
    public PaidStatus $paidStatus;

    public ?int $commercialId;

    public ?int $carLoadId;

    public ?int $customerId;

    /** @var int[]|null */
    public ?array $customerIds;

    public ?string $type;

    public function __construct(
        PaidStatus $paidStatus = PaidStatus::All,
        ?int $commercialId = null,
        ?int $carLoadId = null,
        ?int $customerId = null,
        ?array $customerIds = null,
        ?string $type = null,
    ) {
        $this->paidStatus = $paidStatus;
        $this->commercialId = $commercialId;
        $this->carLoadId = $carLoadId;
        $this->customerId = $customerId;
        $this->customerIds = $customerIds;
        $this->type = $type;
    }

    // -------------------------------------------------------------------------
    // Static entry points — start a filter chain from a named intent
    // -------------------------------------------------------------------------

    public static function new(): static
    {
        return new static;
    }

    public static function thatArePaid(): static
    {
        return new static(paidStatus: PaidStatus::PaidOnly);
    }

    public static function thatAreUnpaid(): static
    {
        return new static(paidStatus: PaidStatus::UnpaidOnly);
    }

    public static function regardlessOfPaymentStatus(): static
    {
        return new static(paidStatus: PaidStatus::All);
    }

    // -------------------------------------------------------------------------
    // Chainable modifiers — each returns a new immutable instance
    // -------------------------------------------------------------------------

    public function thatAreMadeByCommercial(int $commercialId): static
    {
        $clone = clone $this;
        $clone->commercialId = $commercialId;

        return $clone;
    }

    public function forCustomer(int $customerId): static
    {
        $clone = clone $this;
        $clone->customerId = $customerId;

        return $clone;
    }

    /**
     * Filter to invoices/ventes for any of the given customer IDs.
     *
     * @param  int[]  $customerIds
     */
    public function forCustomers(array $customerIds): static
    {
        $clone = clone $this;
        $clone->customerIds = $customerIds;

        return $clone;
    }

    public function thatAreInCarLoad(int $carLoadId): static
    {
        $clone = clone $this;
        $clone->carLoadId = $carLoadId;

        return $clone;
    }

    public function ofType(string $type): static
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }
}
