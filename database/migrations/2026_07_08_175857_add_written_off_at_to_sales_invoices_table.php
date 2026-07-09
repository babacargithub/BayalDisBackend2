<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Null = active. Non-null marks the invoice as definitively lost (uncollectible
            // debt), excluding it from every debt/AR aggregation via the SalesInvoice
            // global scope. Kept in the database for audit purposes only.
            $table->timestamp('written_off_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('written_off_at');
        });
    }
};
