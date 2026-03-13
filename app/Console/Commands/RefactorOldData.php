<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Single entry-point that runs every historical-data-refactoring command
 * in the correct dependency order:
 *
 *  Step 1 — bayal:migrate-single-ventes-to-invoices
 *            Wraps legacy TYPE_SINGLE ventes into proper SalesInvoices with
 *            back-dated payments. Runs first because subsequent steps rely on
 *            every vente having a parent invoice.
 *
 *  Step 2 — bayal:correct-cost-prices-and-profits
 *            Recomputes product cost prices from purchase history, then
 *            recalculates vente profits, payment profits, and all invoice
 *            stored totals in one clean pass. Must run after migration so
 *            the newly created invoices are also corrected.
 *
 *  Step 3 — bayal:link-invoices-to-car-loads
 *            Backfills car_load_id on invoices that predate the field. Runs
 *            last because it only needs invoices to exist and be final.
 *
 * The --dry-run flag is forwarded to every sub-command so you can preview
 * the full impact of all three steps without writing anything.
 */
class RefactorOldData extends Command
{
    protected $signature = 'bayal:refactor-old-data
                            {--dry-run : Preview all changes without writing to the database}';

    protected $description = 'Run all historical-data-refactoring commands in the correct order: migrate ventes → correct profits → link car loads.';

    /**
     * Ordered list of sub-commands to execute.
     * Each entry is an array of [command-name, options].
     *
     * @var array<int, array{command: string, options: array<string, mixed>}>
     */
    private const PIPELINE = [
        [
            'command' => 'bayal:migrate-single-ventes-to-invoices',
            'label' => 'Step 1/3 — Migrate legacy TYPE_SINGLE ventes to SalesInvoices',
        ],
        [
            'command' => 'bayal:correct-cost-prices-and-profits',
            'label' => 'Step 2/3 — Correct cost prices and recalculate all profits',
        ],
        [
            'command' => 'bayal:link-invoices-to-car-loads',
            'label' => 'Step 3/3 — Backfill car_load_id on legacy invoices',
        ],
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if (! $this->allMigrationsHaveBeenRun()) {
            $this->error('There are pending database migrations. Run `php artisan migrate` first, then retry.');

            return Command::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('═══════════════════════════════════════════════════════════');
            $this->warn('  DRY RUN — no changes will be written to the database.');
            $this->warn('═══════════════════════════════════════════════════════════');
        } else {
            $this->info('═══════════════════════════════════════════════════════════');
            $this->info('  bayal:refactor-old-data — starting full pipeline');
            $this->info('═══════════════════════════════════════════════════════════');
        }

        foreach (self::PIPELINE as $step) {
            $this->newLine();
            $this->info('───────────────────────────────────────────────────────────');
            $this->info("  {$step['label']}");
            $this->info('───────────────────────────────────────────────────────────');

            $subCommandOptions = $isDryRun ? ['--dry-run' => true] : [];

            $exitCode = $this->call($step['command'], $subCommandOptions);

            if ($exitCode !== Command::SUCCESS) {
                $this->error("Sub-command [{$step['command']}] failed with exit code {$exitCode}. Pipeline aborted.");

                return Command::FAILURE;
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');

        if ($isDryRun) {
            $this->warn('  Dry-run complete. Re-run without --dry-run to apply.');
        } else {
            $this->info('  All steps completed successfully.');
        }

        $this->info('═══════════════════════════════════════════════════════════');

        return Command::SUCCESS;
    }

    private function allMigrationsHaveBeenRun(): bool
    {
        $ranMigrations = DB::table('migrations')->pluck('migration')->all();

        $allMigrationFiles = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME));

        $pendingMigrations = $allMigrationFiles->reject(
            fn (string $filename) => in_array($filename, $ranMigrations, strict: true)
        );

        if ($pendingMigrations->isNotEmpty()) {
            $this->warn('Pending migrations:');
            $pendingMigrations->each(fn (string $filename) => $this->line("  • {$filename}"));
        }

        return $pendingMigrations->isEmpty();
    }
}
