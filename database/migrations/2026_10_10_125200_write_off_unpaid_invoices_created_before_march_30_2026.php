<?php

use App\Models\SalesInvoice;
use App\Services\SalesInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Invoices created strictly before this instant and carrying no payment are
     * considered definitively lost debt.
     */
    private const WRITE_OFF_CUTOFF_DATE = '2026-03-30';

    /**
     * Data migration: writes off every unpaid invoice created before 30 March 2026.
     *
     * Goes through SalesInvoiceService::writeOffInvoice() so the same rules apply as
     * the bayal:write-off-unpaid-invoices-before-date command. Already written-off
     * invoices are excluded by the SalesInvoice global scope, which makes this
     * migration idempotent. Partially-paid invoices are left untouched.
     */
    public function up(): void
    {
        $salesInvoiceService = app(SalesInvoiceService::class);
        $cutoffInstant = CarbonImmutable::parse(self::WRITE_OFF_CUTOFF_DATE)->startOfDay();

        DB::transaction(function () use ($salesInvoiceService, $cutoffInstant): void {
            SalesInvoice::query()
                ->where('created_at', '<', $cutoffInstant)
                ->where('total_payments', 0)
                ->chunkById(200, function ($unpaidInvoicesChunk) use ($salesInvoiceService): void {
                    foreach ($unpaidInvoicesChunk as $unpaidInvoice) {
                        $salesInvoiceService->writeOffInvoice($unpaidInvoice);
                    }
                });
        });
    }

    /**
     * Intentionally a no-op: written-off invoices cannot be told apart from invoices
     * written off manually before or after this migration, so restoring them
     * automatically could resurrect debt that was legitimately declared lost.
     * Use withWrittenOff() and clear written_off_at manually if a rollback is needed.
     */
    public function down(): void {}
};
