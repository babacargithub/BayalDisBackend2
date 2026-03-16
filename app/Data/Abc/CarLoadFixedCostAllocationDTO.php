<?php

namespace App\Data\Abc;

/**
 * Holds the allocated share of monthly fixed costs (storage + overhead)
 * for a single CarLoad, computed by AbcFixedCostDistributionService.
 */
final readonly class CarLoadFixedCostAllocationDTO
{
    public function __construct(
        public int $storageAllocation,
        public int $overheadAllocation,
    ) {}

    public function total(): int
    {
        return $this->storageAllocation + $this->overheadAllocation;
    }

    public static function zero(): self
    {
        return new self(
            storageAllocation: 0,
            overheadAllocation: 0,
        );
    }
}
