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
            // Delivery cost for this invoice: the fraction of the car load's daily running cost
            // (vehicle fixed costs + fuel) allocated to this invoice.
            // For car-load invoices, this is auto-computed by RecalculateInvoicesDeliveryCostJob
            // and distributed equally across all invoices created on the same day for the same
            // car load. For back-office invoices (no car_load_id), this may be set manually.
            $table->unsignedInteger('delivery_cost')->nullable()->after('estimated_commercial_commission');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('delivery_cost');
        });
    }
};
