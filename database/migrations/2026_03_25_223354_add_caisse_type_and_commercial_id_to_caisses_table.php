<?php

use App\Enums\CaisseType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caisses', function (Blueprint $table): void {
            $table->string('caisse_type')
                ->default(CaisseType::Main->value)
                ->after('name');

            $table->foreignId('commercial_id')
                ->nullable()
                ->after('caisse_type')
                ->constrained('commercials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('caisses', function (Blueprint $table): void {
            $table->dropForeign(['commercial_id']);
            $table->dropColumn(['commercial_id', 'caisse_type']);
        });
    }
};
