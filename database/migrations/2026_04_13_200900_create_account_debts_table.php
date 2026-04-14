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
        Schema::create('account_debts', function (Blueprint $table): void {
            $table->id();

            // The account that borrowed money (e.g. MERCHANDISE_SALES).
            $table->foreignId('debtor_account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            // The account that lent its balance (e.g. VehicleDepreciation).
            $table->foreignId('creditor_account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            // The initial borrowed amount. Never updated after creation.
            $table->integer('original_amount');

            // How much is still owed. Decreases with each repayment.
            $table->integer('remaining_amount');

            // PENDING | PARTIALLY_REPAID | FULLY_REPAID
            $table->string('status')->default('PENDING');

            // Free-text description of why the debt was created.
            $table->string('reason');

            $table->timestamps();

            $table->index('status');
            $table->index('debtor_account_id');
            $table->index('creditor_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_debts');
    }
};
