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
            $table->unsignedBigInteger('total_amount')->default(0)->after('car_load_id');
            $table->unsignedBigInteger('total_payments')->default(0)->after('total_amount');
            $table->bigInteger('total_estimated_profit')->default(0)->after('total_payments');
            $table->bigInteger('total_realized_profit')->default(0)->after('total_estimated_profit');
            $table->string('status')->default('DRAFT')->after('total_realized_profit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'total_amount',
                'total_payments',
                'total_estimated_profit',
                'total_realized_profit',
                'status',
            ]);
        });
    }
};
