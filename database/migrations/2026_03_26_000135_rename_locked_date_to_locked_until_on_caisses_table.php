<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only rename if the old column name exists.
        // On fresh databases (e.g. SQLite used in tests), the original migration
        // already creates the column as "locked_until", so no rename is needed.
        if (Schema::hasColumn('caisses', 'locked_date')) {
            Schema::table('caisses', function (Blueprint $table): void {
                $table->renameColumn('locked_date', 'locked_until');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('caisses', 'locked_until')) {
            Schema::table('caisses', function (Blueprint $table): void {
                $table->renameColumn('locked_until', 'locked_date');
            });
        }
    }
};
