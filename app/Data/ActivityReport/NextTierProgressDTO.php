<?php

namespace App\Data\ActivityReport;

/**
 * Represents progress toward the next unachieved commission objective tier.
 *
 * Returned when a commercial has already met the mandatory daily sales threshold
 * and has not yet reached the highest available tier for the current work period.
 *
 * All amounts are integers (XOF).
 */
readonly class NextTierProgressDTO
{
    public function __construct(
        /** The tier_level of the next nearest unachieved tier. */
        public int $tierLevel,

        /** The daily encaissement (payments collected) required to unlock this tier. */
        public int $caThreshold,

        /** The bonus amount that will be earned if this tier is reached. */
        public int $bonusAmount,

        /** How much more encaissement is needed: ca_threshold − current_daily_encaissement. */
        public int $missingAmount,
    ) {}

    public function toArray(): array
    {
        return [
            'tier_level' => $this->tierLevel,
            'ca_threshold' => $this->caThreshold,
            'bonus_amount' => $this->bonusAmount,
            'missing_amount' => $this->missingAmount,
        ];
    }
}
