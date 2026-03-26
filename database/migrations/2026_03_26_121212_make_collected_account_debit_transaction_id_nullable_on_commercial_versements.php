<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make collected_account_debit_transaction_id nullable so that versements performed
     * after a "Clôturer Journée" (which already drained COMMERCIAL_COLLECTED to zero)
     * can be recorded without a collected-account debit transaction.
     */
    public function up(): void
    {
        Schema::table('commercial_versements', function (Blueprint $table): void {
            $table->unsignedBigInteger('collected_account_debit_transaction_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_versements', function (Blueprint $table): void {
            $table->unsignedBigInteger('collected_account_debit_transaction_id')->nullable(false)->change();
        });
    }
};
