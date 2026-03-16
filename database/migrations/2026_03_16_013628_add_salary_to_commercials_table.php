<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercials', function (Blueprint $table) {
            $table->unsignedInteger('salary')
                ->default(0)
                ->after('gender')
                ->comment('Fixed monthly salary in XOF');
        });
    }

    public function down(): void
    {
        Schema::table('commercials', function (Blueprint $table) {
            $table->dropColumn('salary');
        });
    }
};
