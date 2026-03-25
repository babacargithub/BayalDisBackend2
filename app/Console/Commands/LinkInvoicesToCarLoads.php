<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills car_load_id on SalesInvoice rows that were created before the
 * service started writing the field.
 *
 * Two-pass matching strategy
 * --------------------------
 * Pass 1 — Exact window match:
 *   Find a car load for the commercial's team whose date window contains the
 *   invoice's created_at:
 *       car_loads.load_date  ≤  invoice.created_at  ≤  car_loads.return_date
 *
 *   If exactly one matches → link it.
 *   If multiple match (overlapping windows) → pick the one with the most recent
 *   load_date before the invoice.
 *
 * Pass 2 — Closest preceding car load (gap fallback):
 *   When no car load window covers the invoice date (e.g. sales continued after
 *   a car load's planned return_date but before the next one was opened in the
 *   system), assign the car load whose return_date is the most recent date
 *   BEFORE the invoice. This is the real-world car load the team was still
 *   operating on.
 *
 *   Investigation revealed that 695 invoices (Team #1, Nov 17 2025 → Jan 23
 *   2026) all fall in a gap after car load #8 ended on Nov 16 2025 — the team
 *   kept selling but no new car load record was created. All 695 correctly
 *   belong to car load #8.
 *
 * Pass 3 — Earliest car load (pre-history fallback):
 *   When the invoice predates every car load for the team (sales happened before
 *   the first car load was ever opened in the system), assign it to the team's
 *   earliest car load by load_date. This covers the Jan–Feb 2025 period where
 *   invoices were recorded before car load #1 was created on 2025-02-21.
 *
 * If no car load exists at all for that team → the invoice keeps car_load_id = NULL
 * and is logged as unresolvable.
 *
 * Always run with --dry-run first to review changes before committing.
 */
class LinkInvoicesToCarLoads extends Command
{
    protected $signature = 'bayal:link-invoices-to-car-loads
                            {--dry-run : Preview all changes without writing to the database}';

    protected $description = 'Backfill car_load_id on legacy SalesInvoice rows that are not yet linked to a car load.';

    private bool $isDryRun = false;

    private int $linkedByWindowCount = 0;

    private int $linkedByFallbackCount = 0;

    private int $linkedByEarliestCarLoadCount = 0;

    private int $skippedNoMatchCount = 0;

    private int $skippedNoCommercialCount = 0;

    private array $teamIdCache = [];

    /** @var array<int, int|null> Cache of customer_id → commercial_id lookups. */
    private array $customerCommercialIdCache = [];

    public function handle(): int
    {
        $this->isDryRun = (bool) $this->option('dry-run');

        if ($this->isDryRun) {
            $this->warn('DRY RUN — no changes will be written to the database.');
        }

        $unlinkedInvoices = $this->loadUnlinkedInvoices();

        if ($unlinkedInvoices->isEmpty()) {
            $this->info('No unlinked invoices found. Nothing to do.');

            return Command::SUCCESS;
        }

        $this->info("Found {$unlinkedInvoices->count()} invoices without a car_load_id.");

        $carLoadsByTeam = $this->loadCarLoadsByTeam();

        $this->withProgressBar($unlinkedInvoices, function (object $invoice) use ($carLoadsByTeam): void {
            $this->processInvoice($invoice, $carLoadsByTeam);
        });

        $this->newLine(2);
        $this->printSummary();

        return Command::SUCCESS;
    }

    // ─── Data loading ─────────────────────────────────────────────────────────

    private function loadUnlinkedInvoices(): Collection
    {
        // Include invoices where commercial_id is null but customer_id is set —
        // the commercial can be resolved via customers.commercial_id.
        return DB::table('sales_invoices')
            ->whereNull('car_load_id')
            ->where(function ($query): void {
                $query->whereNotNull('commercial_id')
                    ->orWhereNotNull('customer_id');
            })
            ->orderBy('created_at')
            ->get(['id', 'commercial_id', 'customer_id', 'created_at']);
    }

    /**
     * All car loads grouped by team_id, sorted oldest load_date first.
     *
     * @return Collection<int, Collection<int, object{id:int, load_date:string, return_date:string}>>
     */
    private function loadCarLoadsByTeam(): Collection
    {
        return DB::table('car_loads')
            ->orderBy('load_date')
            ->get(['id', 'team_id', 'load_date', 'return_date'])
            ->groupBy('team_id');
    }

    // ─── Per-invoice matching ─────────────────────────────────────────────────

    private function processInvoice(object $invoice, Collection $carLoadsByTeam): void
    {
        // Prefer the commercial directly on the invoice; fall back to the
        // customer's commercial for invoices migrated without a commercial_id
        // (e.g. those created by bayal:migrate-single-ventes-to-invoices).
        $commercialId = $invoice->commercial_id
            ?? $this->resolveCommercialIdFromCustomer($invoice->customer_id ?? null);

        $teamId = $commercialId !== null ? $this->resolveTeamId($commercialId) : null;

        if ($teamId === null) {
            $this->skippedNoCommercialCount++;

            return;
        }

        $allTeamCarLoads = $carLoadsByTeam->get($teamId, collect());
        $invoiceCreatedAt = Carbon::parse($invoice->created_at);

        // ── Pass 1: exact date-window match ───────────────────────────────────
        $windowMatches = $allTeamCarLoads->filter(
            fn (object $carLoad) => $invoiceCreatedAt->between(
                Carbon::parse($carLoad->load_date),
                Carbon::parse($carLoad->return_date),
            )
        );

        if ($windowMatches->isNotEmpty()) {
            $bestCarLoad = $windowMatches->sortByDesc('load_date')->first();
            $this->applyLink($invoice->id, $bestCarLoad->id, 'window');
            $this->linkedByWindowCount++;

            return;
        }

        // ── Pass 2: closest preceding car load (gap fallback) ─────────────────
        // Used when the team kept selling after the planned return_date but no
        // new car load was opened in the system yet.
        $precedingCarLoad = $allTeamCarLoads
            ->filter(fn (object $carLoad) => Carbon::parse($carLoad->return_date)->lt($invoiceCreatedAt))
            ->sortByDesc('return_date')
            ->first();

        if ($precedingCarLoad !== null) {
            $gapDays = (int) Carbon::parse($precedingCarLoad->return_date)->diffInDays($invoiceCreatedAt);
            $this->applyLink($invoice->id, $precedingCarLoad->id, "gap fallback (+{$gapDays}d after CL#{$precedingCarLoad->id})");
            $this->linkedByFallbackCount++;

            return;
        }

        // ── Pass 3: earliest car load (pre-history fallback) ──────────────────
        // Used when the invoice predates all car loads for the team — i.e. sales
        // happened before the first car load was ever opened in the system.
        // Assign to the earliest car load by load_date.
        $earliestCarLoad = $allTeamCarLoads->sortBy('load_date')->first();

        if ($earliestCarLoad !== null) {
            $daysBeforeStart = (int) $invoiceCreatedAt->diffInDays(Carbon::parse($earliestCarLoad->load_date));
            $this->applyLink($invoice->id, $earliestCarLoad->id, "pre-history fallback ({$daysBeforeStart}d before CL#{$earliestCarLoad->id})");
            $this->linkedByEarliestCarLoadCount++;

            return;
        }

        // ── Unresolvable ──────────────────────────────────────────────────────
        $this->skippedNoMatchCount++;
        $this->line(
            "  <comment>Unresolvable</comment> — invoice #{$invoice->id} created {$invoiceCreatedAt->toDateString()}: team #{$teamId} has no car loads at all"
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveTeamId(int $commercialId): ?int
    {
        if (! isset($this->teamIdCache[$commercialId])) {
            $this->teamIdCache[$commercialId] = DB::table('commercials')
                ->where('id', $commercialId)
                ->value('team_id');
        }

        return $this->teamIdCache[$commercialId];
    }

    /**
     * Looks up the commercial_id for a customer, with per-request caching.
     * Returns null if the customer has no commercial or the customer does not exist.
     */
    private function resolveCommercialIdFromCustomer(?int $customerId): ?int
    {
        if ($customerId === null) {
            return null;
        }

        if (! isset($this->customerCommercialIdCache[$customerId])) {
            $this->customerCommercialIdCache[$customerId] = DB::table('customers')
                ->where('id', $customerId)
                ->value('commercial_id');
        }

        return $this->customerCommercialIdCache[$customerId];
    }

    // ─── Write ────────────────────────────────────────────────────────────────

    private function applyLink(int $invoiceId, int $carLoadId, string $method): void
    {
        if ($this->isDryRun) {
            $this->line("  <info>Would link</info> invoice #{$invoiceId} → car load #{$carLoadId} ({$method})");

            return;
        }

        DB::table('sales_invoices')
            ->where('id', $invoiceId)
            ->update(['car_load_id' => $carLoadId]);
    }

    // ─── Summary ─────────────────────────────────────────────────────────────

    private function printSummary(): void
    {
        $verb = $this->isDryRun ? 'Would link' : 'Linked';
        $total = $this->linkedByWindowCount + $this->linkedByFallbackCount + $this->linkedByEarliestCarLoadCount;

        $this->info("{$verb}: {$total} invoice(s) total.");
        $this->line("  • {$this->linkedByWindowCount} via exact date window match.");
        $this->line("  • {$this->linkedByFallbackCount} via closest preceding car load (gap fallback).");
        $this->line("  • {$this->linkedByEarliestCarLoadCount} via earliest car load (pre-history fallback).");

        if ($this->skippedNoMatchCount > 0) {
            $this->warn("Unresolvable: {$this->skippedNoMatchCount} invoice(s) — team has no car loads at all, car_load_id stays NULL.");
        }

        if ($this->skippedNoCommercialCount > 0) {
            $this->warn("Skipped: {$this->skippedNoCommercialCount} invoice(s) have a commercial with no team.");
        }
    }
}
