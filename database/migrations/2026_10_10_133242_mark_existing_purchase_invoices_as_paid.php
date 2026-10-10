<?php

use App\Models\PurchaseInvoice;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Every purchase invoice existing before supplier debts were tracked on the
     * admin rapport is settled, so flag them all as paid.
     *
     * Not reversible: the previous per-invoice values are not kept.
     */
    public function up(): void
    {
        PurchaseInvoice::query()
            ->where('is_paid', false)
            ->update(['is_paid' => true]);
    }

    public function down(): void {}
};
