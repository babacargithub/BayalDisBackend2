<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Weighted product diversity score for this invoice (1–100).
            // 0 means no items yet. Recomputed by SalesInvoice::recalculateStoredTotals()
            // whenever invoice items change.
            $table->decimal('push_score', 5, 2)->default(0)->after('estimated_commercial_commission');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('push_score');
        });
    }
};
