<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->foreignId('caisse_id')->nullable()->constrained('caisses');
        });
    }

    public function down()
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropForeign(['caisse_id']);
            $table->dropColumn('caisse_id');
        });
    }
}; 