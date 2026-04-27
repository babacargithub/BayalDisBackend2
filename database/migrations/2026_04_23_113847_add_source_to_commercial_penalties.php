<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds optional source traceability to penalties created via the HR reconciliation page.
 *
 * A penalty may originate from a closed inventory deficit (car_load_inventory_id)
 * or an overdue invoice (sales_invoice_id). Both are nullable so existing penalties
 * and manually entered ones remain valid. The two FKs are mutually exclusive in
 * practice but the schema does not enforce this — business logic does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->foreignId('car_load_inventory_id')
                ->nullable()
                ->after('work_day')
                ->constrained('car_load_inventories')
                ->nullOnDelete();

            $table->foreignId('sales_invoice_id')
                ->nullable()
                ->after('car_load_inventory_id')
                ->constrained('sales_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\CarLoadInventory::class);
            $table->dropForeignIdFor(\App\Models\SalesInvoice::class);
            $table->dropColumn(['car_load_inventory_id', 'sales_invoice_id']);
        });
    }
};
