<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->enum('type', ['SINGLE', 'INVOICE_ITEM'])->default('SINGLE');
        });

        // Update existing ventes to be marked as SINGLE
        DB::table('ventes')->update(['type' => 'SINGLE']);
    }

    public function down()
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
}; 