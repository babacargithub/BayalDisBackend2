<?php

namespace App\Console\Commands;

use App\Enums\CarLoadStatus;
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
 *  Step 4 — backfillCarLoadStatuses (inline)
 *            Sets all car loads to TERMINATED_AND_TRANSFERRED except the most
 *            recent one (ordered by id), which is set to ONGOING_INVENTORY to
 *            reflect that the current cycle is at the inventory stage.
 *
 *  Step 5 — backfillStockEntryWarehouse (inline)
 *            Creates the "Dépôt Ouest Foire" warehouse if it does not yet
 *            exist, then links every stock_entry that has no warehouse_id to
 *            that warehouse. This represents the single physical depot from
 *            which all historical stock was loaded.
 *
 * The --dry-run flag is forwarded to every sub-command so you can preview
 * the full impact of all steps without writing anything.
 */
class RefactorOldData extends Command
{
    protected $signature = 'bayal:refactor-old-data
                            {--dry-run : Preview all changes without writing to the database}';

    protected $description = 'Run all historical-data-refactoring commands in the correct order: migrate ventes → correct profits → link car loads → backfill statuses → link stock entries to warehouse.';

    private const DEPOT_OUEST_FOIRE_NAME = 'Dépôt Ouest Foire';

    private const DEPOT_OUEST_FOIRE_ADDRESS = 'Ouest Foire, Dakar';

    /**
     * Ordered list of sub-commands to execute.
     * Each entry is an array of [command-name, options].
     *
     * @var array<int, array{command: string, label: string}>
     */
    private const PIPELINE = [
        [
            'command' => 'bayal:migrate-single-ventes-to-invoices',
            'label' => 'Step 1/5 — Migrate legacy TYPE_SINGLE ventes to SalesInvoices',
        ],
        [
            'command' => 'bayal:correct-cost-prices-and-profits',
            'label' => 'Step 2/5 — Correct cost prices and recalculate all profits',
        ],
        [
            'command' => 'bayal:link-invoices-to-car-loads',
            'label' => 'Step 3/5 — Backfill car_load_id on legacy invoices',
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

        // Step 4 runs inline (no sub-command)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 4/5 — Backfill car load statuses');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillCarLoadStatuses($isDryRun);

        // Step 5 runs inline (no sub-command)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 5/5 — Create "'.self::DEPOT_OUEST_FOIRE_NAME.'" and link stock entries');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillStockEntryWarehouse($isDryRun);

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

    /**
     * Creates the "Dépôt Ouest Foire" warehouse if it does not exist, then
     * links every stock_entry whose warehouse_id is NULL to that warehouse.
     *
     * Uses the first user in the database as the warehouse manager, since
     * historical entries predate per-warehouse manager assignment.
     *
     * This is idempotent — re-running it is safe (already-linked entries are skipped).
     */
    private function backfillStockEntryWarehouse(bool $isDryRun): void
    {
        $firstUser = DB::table('users')->orderBy('id')->first(['id', 'name']);

        if (! $firstUser) {
            $this->warn('  No users found in the database. Cannot create warehouse without a manager. Skipping.');

            return;
        }

        $unlinkedCount = DB::table('stock_entries')->whereNull('warehouse_id')->count();

        if ($unlinkedCount === 0) {
            $this->info('  All stock entries already have a warehouse. Nothing to do.');

            return;
        }

        $this->line("  {$unlinkedCount} stock entries have no warehouse_id.");

        // Find or preview the warehouse
        $existingWarehouse = DB::table('warehouses')
            ->where('name', self::DEPOT_OUEST_FOIRE_NAME)
            ->first(['id', 'name']);

        if ($existingWarehouse) {
            $this->line("  Warehouse «{$existingWarehouse->name}» already exists (id #{$existingWarehouse->id}).");
            $warehouseId = $existingWarehouse->id;
        } else {
            $this->line('  Warehouse «'.self::DEPOT_OUEST_FOIRE_NAME.'» does not exist yet — will create it.');
            $warehouseId = null;
        }

        if ($isDryRun) {
            $this->warn("  [DRY RUN] Would link {$unlinkedCount} stock entries to «".self::DEPOT_OUEST_FOIRE_NAME.'».');

            return;
        }

        if (! $existingWarehouse) {
            $warehouseId = DB::table('warehouses')->insertGetId([
                'name' => self::DEPOT_OUEST_FOIRE_NAME,
                'address' => self::DEPOT_OUEST_FOIRE_ADDRESS,
                'manager_id' => $firstUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info('  Created warehouse «'.self::DEPOT_OUEST_FOIRE_NAME."» (id #{$warehouseId}), manager: «{$firstUser->name}» (id #{$firstUser->id}).");
        }

        $updatedCount = DB::table('stock_entries')
            ->whereNull('warehouse_id')
            ->update(['warehouse_id' => $warehouseId]);

        $this->info("  Done — {$updatedCount} stock entries linked to warehouse #{$warehouseId}.");
    }

    /**
     * Sets all car loads to TERMINATED_AND_TRANSFERRED, except the most recent
     * one (by id) which is set to ONGOING_INVENTORY to reflect that the current
     * cycle is still being reconciled.
     *
     * This is idempotent — re-running it is safe.
     */
    private function backfillCarLoadStatuses(bool $isDryRun): void
    {
        $allCarLoads = DB::table('car_loads')->orderBy('id')->get(['id', 'name', 'status']);

        if ($allCarLoads->isEmpty()) {
            $this->info('  No car loads found. Nothing to do.');

            return;
        }

        $latestCarLoadId = $allCarLoads->last()->id;

        $terminatedCount = 0;
        $skippedCount = 0;

        foreach ($allCarLoads as $carLoad) {
            $targetStatus = $carLoad->id === $latestCarLoadId
                ? CarLoadStatus::OngoingInventory->value
                : CarLoadStatus::TerminatedAndTransferred->value;

            if ($carLoad->status === $targetStatus) {
                $this->line("  Skip car load #{$carLoad->id} «{$carLoad->name}»: already {$targetStatus}");
                $skippedCount++;

                continue;
            }

            $this->line("  Car load #{$carLoad->id} «{$carLoad->name}»: {$carLoad->status} → {$targetStatus}");

            if (! $isDryRun) {
                DB::table('car_loads')
                    ->where('id', $carLoad->id)
                    ->update(['status' => $targetStatus]);
            }

            $terminatedCount++;
        }

        $this->info("  Done — {$terminatedCount} car loads updated, {$skippedCount} already correct.");
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
