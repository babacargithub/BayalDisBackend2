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
        Schema::table('daily_commissions', function (Blueprint $table) {
            // Stores the ca_threshold of the mandatory tier for display purposes.
            // Null when no mandatory tier is configured for the period.
            $table->unsignedBigInteger('mandatory_tier_threshold')->nullable()->after('cached_average_margin_rate');
            // True when no mandatory tier is configured, or when daily encaissement >= mandatory tier threshold.
            $table->boolean('mandatory_tier_threshold_reached')->default(true)->after('mandatory_tier_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table) {
            $table->dropColumn(['mandatory_tier_threshold', 'mandatory_tier_threshold_reached']);
        });
    }
};
