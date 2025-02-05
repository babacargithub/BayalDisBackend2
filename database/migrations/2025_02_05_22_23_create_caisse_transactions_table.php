<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('caisse_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caisse_id')->constrained()->onDelete('cascade');
            $table->integer('amount');
            $table->string('label')->nullable();
            $table->string('transaction_type');

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('caisse_transactions');
    }
}; 