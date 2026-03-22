<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table) {
            $table->integer('new_confirmed_customers_bonus')->default(0)->after('total_penalties');
            $table->integer('new_prospect_customers_bonus')->default(0)->after('new_confirmed_customers_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table) {
            $table->dropColumn(['new_confirmed_customers_bonus', 'new_prospect_customers_bonus']);
        });
    }
};
