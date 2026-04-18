<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        Schema::rename('visit_batches', 'beats');
        Schema::rename('customer_visits', 'beat_stops');

        Schema::table('beat_stops', function (Blueprint $table) {
            $table->renameColumn('visit_batch_id', 'beat_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        Schema::table('beat_stops', function (Blueprint $table) {
            $table->renameColumn('beat_id', 'visit_batch_id');
        });

        Schema::rename('beat_stops', 'customer_visits');
        Schema::rename('beats', 'visit_batches');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
};
