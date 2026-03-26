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
        Schema::create('commercial_versements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('commercial_id')
                ->constrained('commercials')
                ->cascadeOnDelete();

            $table->foreignId('main_caisse_id')
                ->constrained('caisses')
                ->restrictOnDelete();

            $table->date('versement_date');

            // Total cash swept from the commercial's caisse to the main caisse.
            $table->unsignedInteger('amount_versed');

            // Commission credited to the commercial's COMMERCIAL_COMMISSION account.
            $table->unsignedInteger('commission_credited');

            // Remainder credited to the MERCHANDISE_SALES account.
            $table->unsignedInteger('merchandise_credited');

            // Audit trail: IDs of the transactions created atomically.
            $table->unsignedBigInteger('caisse_withdraw_transaction_id');
            $table->foreign('caisse_withdraw_transaction_id', 'cv_caisse_withdraw_fk')
                ->references('id')->on('caisse_transactions')->restrictOnDelete();

            $table->unsignedBigInteger('caisse_deposit_transaction_id');
            $table->foreign('caisse_deposit_transaction_id', 'cv_caisse_deposit_fk')
                ->references('id')->on('caisse_transactions')->restrictOnDelete();

            $table->unsignedBigInteger('collected_account_debit_transaction_id');
            $table->foreign('collected_account_debit_transaction_id', 'cv_collected_debit_fk')
                ->references('id')->on('account_transactions')->restrictOnDelete();

            $table->unsignedBigInteger('commission_account_transaction_id')->nullable();
            $table->foreign('commission_account_transaction_id', 'cv_commission_txn_fk')
                ->references('id')->on('account_transactions')->nullOnDelete();

            $table->unsignedBigInteger('merchandise_account_transaction_id')->nullable();
            $table->foreign('merchandise_account_transaction_id', 'cv_merchandise_txn_fk')
                ->references('id')->on('account_transactions')->nullOnDelete();

            $table->timestamps();

            $table->index(['commercial_id', 'versement_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_versements');
    }
};
