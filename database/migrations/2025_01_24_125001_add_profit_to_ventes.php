<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->integer('profit')->default(0);
        });
    }

    public function down()
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn('profit');
        });
    }
}; 