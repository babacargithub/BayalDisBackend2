<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ventes', function (Blueprint $table) {
            // check if the order_id is not already in the table
            if (!Schema::hasColumn('ventes', 'order_id')) {
                $table->foreignId('order_id')->nullable()->after('commercial_id')->constrained()->nullOnDelete();
            }
        });
    }

    public function down()
    {
        Schema::table('ventes', function (Blueprint $table) {
            if (Schema::hasColumn('ventes', 'order_id')) {
                $table->dropForeign(['order_id']);
                $table->dropColumn('order_id');
            }
        });
    }
}; 