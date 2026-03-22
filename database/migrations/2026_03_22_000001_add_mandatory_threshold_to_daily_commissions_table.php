<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table) {
            $table->integer('mandatory_daily_threshold')->default(0)->after('new_prospect_customers_bonus');
            $table->boolean('mandatory_threshold_reached')->default(false)->after('mandatory_daily_threshold');
            $table->decimal('cached_average_margin_rate', 6, 4)->nullable()->after('mandatory_threshold_reached');
        });
    }

    public function down(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table) {
            $table->dropColumn([
                'mandatory_daily_threshold',
                'mandatory_threshold_reached',
                'cached_average_margin_rate',
            ]);
        });
    }
};
