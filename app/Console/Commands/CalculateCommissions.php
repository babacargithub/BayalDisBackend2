<?php

namespace App\Console\Commands;

use App\Data\Commission\CommissionPeriodData;
use App\Models\Commercial;
use App\Services\Commission\CommissionPeriodService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Computes (or refreshes) commissions for all non-finalized commercials for a given period.
 *
 * The period can be:
 *   - A specific week start date (YYYY-MM-DD):  the Mon → Sat week containing that date.
 *   - A year-month (YYYY-MM):                   the full calendar month (1st → last day).
 *   - Omitted:                                  the current week (Mon → Sat).
 *
 * Usage:
 *   php artisan bayal:calculate-commissions                    # current week
 *   php artisan bayal:calculate-commissions 2026-03-02         # week of 2 Mar 2026 (Mon → Sat)
 *   php artisan bayal:calculate-commissions 2026-03            # full month March 2026
 *   php artisan bayal:calculate-commissions 2026-03-02 --commercial=5
 */
class CalculateCommissions extends Command
{
    protected $signature = 'bayal:calculate-commissions
                            {period? : Week start date (YYYY-MM-DD) or year-month (YYYY-MM); defaults to current week}
                            {--commercial= : Only compute for a specific commercial ID}';

    protected $description = 'Compute or refresh commissions for all commercials for a given period (skips finalized ones).';

    public function handle(CommissionPeriodService $commissionPeriodService): int
    {
        $period = $this->resolvePeriod();

        $this->info("Computing commissions for period: {$period->label()}");

        $commercialsQuery = Commercial::query();

        if ($this->option('commercial') !== null) {
            $commercialsQuery->where('id', (int) $this->option('commercial'));
        }

        $commercials = $commercialsQuery->get();

        if ($commercials->isEmpty()) {
            $this->warn('No commercials found. Nothing to compute.');

            return Command::SUCCESS;
        }

        $successCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($commercials as $commercial) {
            try {
                $commission = $commissionPeriodService->computeOrRefreshCommissionForPeriod(
                    $commercial,
                    $period,
                );

                $this->line(
                    "  ✔ Commercial #{$commercial->id} «{$commercial->name}» — "
                    ."net commission: {$commission->net_commission} F "
                    ."(base: {$commission->base_commission}, basket: {$commission->basket_bonus}, "
                    ."objective: {$commission->objective_bonus}, penalties: -{$commission->total_penalties})"
                );
                $successCount++;
            } catch (RuntimeException $exception) {
                if (str_contains($exception->getMessage(), 'already finalized')) {
                    $this->line("  ⊘ Commercial #{$commercial->id} «{$commercial->name}» — skipped (already finalized).");
                    $skippedCount++;
                } else {
                    $this->error("  ✘ Commercial #{$commercial->id} «{$commercial->name}» — error: {$exception->getMessage()}");
                    $errorCount++;
                }
            }
        }

        $this->newLine();
        $this->info("Done — {$successCount} computed, {$skippedCount} skipped (finalized), {$errorCount} errors.");

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolvePeriod(): CommissionPeriodData
    {
        $periodArgument = $this->argument('period');

        if ($periodArgument === null) {
            // Default: current week (Mon → Sat).
            return CommissionPeriodData::weekly(Carbon::now());
        }

        // Year-month format: YYYY-MM
        if (preg_match('/^\d{4}-\d{2}$/', $periodArgument)) {
            $parsed = \DateTime::createFromFormat('Y-m', $periodArgument);
            if ($parsed === false) {
                $this->error("Invalid year-month format «{$periodArgument}». Expected YYYY-MM, e.g. 2026-03.");
                exit(Command::FAILURE);
            }

            return CommissionPeriodData::monthly((int) $parsed->format('Y'), (int) $parsed->format('n'));
        }

        // Full date format: YYYY-MM-DD → weekly period containing that date
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodArgument)) {
            return CommissionPeriodData::weekly(Carbon::parse($periodArgument));
        }

        $this->error("Invalid period «{$periodArgument}». Use YYYY-MM-DD (week) or YYYY-MM (month).");
        exit(Command::FAILURE);
    }
}
