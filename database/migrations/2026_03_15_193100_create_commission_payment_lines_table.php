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
        Schema::create('commission_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained('commissions')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Frozen rate used at computation time (not affected by future rate changes).
            $table->decimal('rate_applied', 8, 4)
                ->comment('Commission rate frozen at computation time, e.g. 0.0100 = 1%');

            // The portion of the payment amount attributed to this product line.
            // For multi-product invoices this is prorated by product revenue share.
            $table->unsignedInteger('payment_amount_allocated')
                ->comment('XOF portion of the payment allocated to this product');

            // commission_amount = payment_amount_allocated × rate_applied (rounded to integer XOF)
            $table->unsignedInteger('commission_amount')
                ->comment('XOF commission earned on this payment line');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_payment_lines');
    }
};
