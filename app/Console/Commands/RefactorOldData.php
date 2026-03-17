<?php

namespace App\Console\Commands;

use App\Enums\CarLoadStatus;
use App\Enums\MonthlyFixedCostPool;
use App\Enums\MonthlyFixedCostSubCategory;
use App\Models\MonthlyFixedCost;
use App\Models\ProductCategory;
use App\Models\Vehicle;
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

    private const DEFAULT_PRODUCT_CATEGORY_NAME = 'JETABLES';

    private const DEFAULT_PRODUCT_CATEGORY_COMMISSION_RATE = '0.0200';

    /**
     * Ordered list of sub-commands to execute.
     * Each entry is an array of [command-name, options].
     *
     * @var array<int, array{command: string, label: string}>
     */
    /**
     * Fleet vehicles to seed on every fresh database.
     * Add new vehicles here as the fleet grows.
     *
     * @var array<int, array<string, mixed>>
     */
    private const FLEET_VEHICLES = [
        [
            'plate_number' => 'AA-531-WL',
            'name' => 'AA-531-WL',
            'insurance_monthly' => 7_000,
            'maintenance_monthly' => 13_500,
            'repair_reserve_monthly' => 25_000,
            'depreciation_monthly' => 60_000,
            'driver_salary_monthly' => 100_000,
            'working_days_per_month' => 26,
        ],
    ];

    /**
     * Monthly fixed cost entries to seed on every fresh database.
     * Each entry is matched by (cost_pool + sub_category + period_year + period_month)
     * to avoid duplicates — safe to re-run.
     *
     * @var array<int, array<string, mixed>>
     */
    private const MONTHLY_FIXED_COSTS = [
        [
            'cost_pool' => MonthlyFixedCostPool::Overhead,
            'sub_category' => MonthlyFixedCostSubCategory::ManagerSalary,
            'amount' => 100_000,
            'label' => 'Salaire manager',
            'period_year' => 2026,
            'period_month' => 3,
        ],
        [
            'cost_pool' => MonthlyFixedCostPool::Storage,
            'sub_category' => MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => 1_000,
            'label' => 'Loyer dépôt',
            'period_year' => 2026,
            'period_month' => 3,
        ],
    ];

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

        // Step 0: seed fleet vehicles (idempotent — skips existing plate numbers)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0/5 — Seed fleet vehicles');
        $this->info('───────────────────────────────────────────────────────────');
        $this->seedFleetVehicles($isDryRun);

        // Step 0.5: seed monthly fixed costs (idempotent — skips existing pool+sub_category+period)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.5/5 — Seed monthly fixed costs');
        $this->info('───────────────────────────────────────────────────────────');
        $this->seedMonthlyFixedCosts($isDryRun);

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
        $this->info('  Step 5/6 — Create "'.self::DEPOT_OUEST_FOIRE_NAME.'" and link stock entries');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillStockEntryWarehouse($isDryRun);

        // Step 6 runs inline (no sub-command)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 6/6 — Seed "'.self::DEFAULT_PRODUCT_CATEGORY_NAME.'" product category and assign all products');
        $this->info('───────────────────────────────────────────────────────────');
        $this->seedDefaultProductCategory($isDryRun);

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
     * Ensures every entry in MONTHLY_FIXED_COSTS exists in the database.
     * Matches by (cost_pool + sub_category + period_year + period_month) — skips if already present.
     * Idempotent: safe to re-run on an already-seeded database.
     */
    private function seedMonthlyFixedCosts(bool $isDryRun): void
    {
        foreach (self::MONTHLY_FIXED_COSTS as $costData) {
            $existing = MonthlyFixedCost::where('cost_pool', $costData['cost_pool'])
                ->where('sub_category', $costData['sub_category'])
                ->where('period_year', $costData['period_year'])
                ->where('period_month', $costData['period_month'])
                ->first();

            $label = $costData['label'];
            $period = $costData['period_year'].'-'.str_pad((string) $costData['period_month'], 2, '0', STR_PAD_LEFT);

            if ($existing !== null) {
                $this->line("  Monthly cost «{$label}» {$period} already exists (id #{$existing->id}). Skipping.");

                continue;
            }

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create monthly cost «{$label}» {$period} — {$costData['amount']} F.");

                continue;
            }

            $cost = MonthlyFixedCost::create($costData);
            $this->info("  Created monthly cost «{$label}» {$period} — {$costData['amount']} F (id #{$cost->id}).");
        }
    }

    /**
     * Ensures every vehicle in FLEET_VEHICLES exists in the database.
     * Matches by plate_number — skips the vehicle if it already exists.
     * Idempotent: safe to re-run on an already-seeded database.
     */
    private function seedFleetVehicles(bool $isDryRun): void
    {
        foreach (self::FLEET_VEHICLES as $vehicleData) {
            $plateNumber = $vehicleData['plate_number'];

            $existingVehicle = Vehicle::where('plate_number', $plateNumber)->first();

            if ($existingVehicle !== null) {
                $this->line("  Vehicle «{$plateNumber}» already exists (id #{$existingVehicle->id}). Skipping.");

                continue;
            }

            $dailyRate = (int) round(
                ($vehicleData['insurance_monthly'] + $vehicleData['maintenance_monthly']
                + $vehicleData['repair_reserve_monthly'] + $vehicleData['depreciation_monthly']
                + $vehicleData['driver_salary_monthly'])
                / $vehicleData['working_days_per_month']
            );

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create vehicle «{$plateNumber}» — daily rate: {$dailyRate} F/jour.");

                continue;
            }

            $vehicle = Vehicle::create($vehicleData);
            $this->info("  Created vehicle «{$plateNumber}» (id #{$vehicle->id}) — daily rate: {$dailyRate} F/jour.");
        }
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

    /**
     * Creates the default "JETABLES" product category (2% commission rate) if it does not
     * yet exist, then assigns every product that has no category to that category.
     *
     * Idempotent: safe to re-run — skips products already assigned to a category.
     */
    private function seedDefaultProductCategory(bool $isDryRun): void
    {
        $categoryName = self::DEFAULT_PRODUCT_CATEGORY_NAME;
        $commissionRate = self::DEFAULT_PRODUCT_CATEGORY_COMMISSION_RATE;

        $existingCategory = ProductCategory::where('name', $categoryName)->first();

        if ($existingCategory !== null) {
            $this->line("  Category «{$categoryName}» already exists (id #{$existingCategory->id}). Skipping creation.");
            $category = $existingCategory;
        } else {
            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create product category «{$categoryName}» with commission rate {$commissionRate}.");
            } else {
                $category = ProductCategory::create([
                    'name' => $categoryName,
                    'commission_rate' => $commissionRate,
                ]);
                $this->info("  Created product category «{$categoryName}» (id #{$category->id}) — commission rate: {$commissionRate}.");
            }
        }

        $productsWithoutCategory = DB::table('products')->whereNull('product_category_id')->count();

        if ($productsWithoutCategory === 0) {
            $this->info('  All products already have a category. Nothing to assign.');

            return;
        }

        $this->line("  {$productsWithoutCategory} product(s) have no category — will assign to «{$categoryName}».");

        if ($isDryRun) {
            $this->warn("  [DRY RUN] Would assign {$productsWithoutCategory} product(s) to «{$categoryName}».");

            return;
        }

        $assignedCount = DB::table('products')
            ->whereNull('product_category_id')
            ->update(['product_category_id' => $category->id]);

        $this->info("  Done — {$assignedCount} product(s) assigned to «{$categoryName}» (id #{$category->id}).");
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
