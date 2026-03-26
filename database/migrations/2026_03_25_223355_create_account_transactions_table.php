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
        Schema::create('account_transactions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            // Always stored as a positive integer.
            $table->unsignedInteger('amount');

            // CREDIT (money in) or DEBIT (money out).
            $table->string('transaction_type', 10);

            $table->string('label')->nullable();

            // Describes what triggered this transaction.
            // Examples: VERSEMENT | DAILY_DISTRIBUTION | PAYMENT | EXPENSE | INITIAL | MANUAL
            $table->string('reference_type', 50)->nullable();

            // ID of the triggering record (e.g. commercial_versement.id, payment.id …).
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'transaction_type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_transactions');
    }
};
