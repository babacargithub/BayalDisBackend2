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
            $table->index('customer_id');
            $table->index('status');
            $table->index('commercial_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['commercial_id']);
        });
    }
};
