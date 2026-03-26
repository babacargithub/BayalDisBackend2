<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table): void {
            // Set when "Clôturer Journée" is called for this commercial's work day.
            // Once finalized, the commission is locked and credited to the
            // COMMERCIAL_COMMISSION account from MERCHANDISE_SALES at that moment.
            // VersementService skips finalized commissions so there is no double-credit.
            $table->timestamp('finalized_at')->nullable()->after('versement_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table): void {
            $table->dropColumn('finalized_at');
        });
    }
};
