<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_new_customer_commission_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commercial_id')->unique();
            $table->foreign('commercial_id', 'cnccs_commercial_id_fk')->references('id')->on('commercials')->cascadeOnDelete();
            $table->integer('confirmed_customer_bonus')->default(0);
            $table->integer('prospect_customer_bonus')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_new_customer_commission_settings');
    }
};
