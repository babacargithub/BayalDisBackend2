<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caisses', function (Blueprint $table): void {
            // When set to today's date the caisse is locked: no new payments are accepted
            // until the date rolls over. Set by "Clôturer Journée" at end of day.
            $table->date('locked_until')->nullable()->after('closed');
        });
    }

    public function down(): void
    {
        Schema::table('caisses', function (Blueprint $table): void {
            $table->dropColumn('locked_until');
        });
    }
};
