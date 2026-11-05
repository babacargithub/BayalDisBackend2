<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * One-off data backfill run automatically on deployment.
 *
 * Invoices created before SalesInvoiceService::createSalesInvoice() was fixed never
 * completed their beat stop nor refreshed the round strike rate. This replays that step
 * for every existing invoice. Idempotent, and a no-op on an empty database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('bayal:backfill-beat-stops-for-invoices');
    }

    public function down(): void
    {
        // Data backfill: nothing to roll back.
    }
};
