<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('visit_batches', function (Blueprint $table) {
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('visit_batches', function (Blueprint $table) {
            $table->dropForeign(['sector_id']);
            $table->dropColumn('sector_id');
        });
    }
}; 