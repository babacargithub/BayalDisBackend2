<?php

namespace App\Data\Commission;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Represents a commission period defined by an inclusive start and end date.
 *
 * Most commercials work on weekly periods (Monday → Saturday).
 * Some work on monthly periods (1st → last day of month).
 * Both are expressed the same way using this DTO.
 *
 * Usage examples:
 *   // Weekly (Mon 2 Mar → Sat 7 Mar 2026)
 *   CommissionPeriodData::weekly(Carbon::parse('2026-03-02'))
 *
 *   // Monthly (March 2026)
 *   CommissionPeriodData::monthly(2026, 3)
 *
 *   // Arbitrary range
 *   new CommissionPeriodData(Carbon::parse('2026-03-02'), Carbon::parse('2026-03-07'))
 */
readonly class CommissionPeriodData
{
    public function __construct(
        /** Inclusive start date of the commission period (typically a Monday). */
        public CarbonImmutable $startDate,
        /** Inclusive end date of the commission period (typically a Saturday). */
        public CarbonImmutable $endDate,
    ) {
        if ($startDate->isAfter($endDate)) {
            throw new \InvalidArgumentException(
                "Commission period start date ({$startDate->toDateString()}) must not be after"
                ." end date ({$endDate->toDateString()})."
            );
        }
    }

    /**
     * Create a weekly period starting on the Monday of the given date's week
     * and ending on the Saturday of the same week (Mon → Sat, 6 days).
     */
    public static function weekly(Carbon|CarbonImmutable $anyDateInWeek): self
    {
        $monday = CarbonImmutable::parse($anyDateInWeek)->startOfWeek(Carbon::MONDAY);
        $saturday = $monday->addDays(5); // Monday + 5 = Saturday

        return new self($monday, $saturday);
    }

    /**
     * Create a monthly period covering the full calendar month (1st → last day).
     */
    public static function monthly(int $year, int $month): self
    {
        $startDate = CarbonImmutable::create($year, $month, 1);
        $endDate = $startDate->endOfMonth()->startOfDay();

        return new self($startDate, $endDate);
    }

    /**
     * Human-readable label, e.g. "2026-03-02 → 2026-03-07".
     */
    public function label(): string
    {
        return $this->startDate->toDateString().' → '.$this->endDate->toDateString();
    }
}
