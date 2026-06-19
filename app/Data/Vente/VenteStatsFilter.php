<?php

namespace App\Data\Vente;

use App\Models\Beat;
use App\Models\Team;
use Carbon\Carbon;

class VenteStatsFilter
{
    public PaidStatus $paidStatus;

    public ?int $commercialId;

    public ?int $carLoadId;

    public ?int $customerId;

    /** @var int[]|null */
    public ?array $customerIds;

    public ?string $type;

    public ?Carbon $startDate;

    public ?Carbon $endDate;

    public ?int $teamId;

    public ?int $beatId;

    /** @var int[]|null */
    public ?array $tagIds;

    public function __construct(
        PaidStatus $paidStatus = PaidStatus::All,
        ?int $commercialId = null,
        ?int $carLoadId = null,
        ?int $customerId = null,
        ?array $customerIds = null,
        ?string $type = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?int $teamId = null,
        ?int $beatId = null,
        ?array $tagIds = null,
    ) {
        $this->paidStatus = $paidStatus;
        $this->commercialId = $commercialId;
        $this->carLoadId = $carLoadId;
        $this->customerId = $customerId;
        $this->customerIds = $customerIds;
        $this->type = $type;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->teamId = $teamId;
        $this->beatId = $beatId;
        $this->tagIds = $tagIds;
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

    /**
     * Restrict to records on or after $start and, when provided, on or before $end.
     * Dates are stored as-is — callers are responsible for applying startOfDay/endOfDay
     * if day-boundary precision is required.
     */
    public function inDateInterval(Carbon $start, ?Carbon $end): static
    {
        $clone = clone $this;
        $clone->startDate = $start;
        $clone->endDate = $end;

        return $clone;
    }

    /**
     * Restrict to records on or after the given date.
     */
    public function from(Carbon $date): static
    {
        $clone = clone $this;
        $clone->startDate = $date;

        return $clone;
    }

    /**
     * Restrict to records on or before the given date.
     */
    public function to(Carbon $date): static
    {
        $clone = clone $this;
        $clone->endDate = $date;

        return $clone;
    }

    /**
     * Restrict to invoices/payments whose commercial belongs to the given team.
     */
    public function thatAreForTeam(Team $team): static
    {
        $clone = clone $this;
        $clone->teamId = $team->id;

        return $clone;
    }

    /**
     * Restrict to invoices/payments for customers who appear in any stop of the given beat.
     */
    public function forCustomersBelongingInBeat(Beat $beat): static
    {
        $clone = clone $this;
        $clone->beatId = $beat->id;

        return $clone;
    }

    /**
     * Restrict to invoices/payments for customers tagged with at least one of the given tag IDs.
     *
     * @param  int[]  $tagIds
     */
    public function forCustomersHavingOneOfTags(array $tagIds): static
    {
        $clone = clone $this;
        $clone->tagIds = $tagIds;

        return $clone;
    }

    /**
     * Whether any invoice-level scope is active.
     *
     * Add a check here whenever a new invoice-scoped field is added to this class,
     * so PaymentService::buildPaymentQuery() automatically picks it up.
     */
    public function hasInvoiceLevelFilters(): bool
    {
        return $this->commercialId !== null
            || $this->customerId !== null
            || $this->customerIds !== null
            || $this->carLoadId !== null
            || $this->teamId !== null
            || $this->beatId !== null
            || $this->tagIds !== null;
    }
}
