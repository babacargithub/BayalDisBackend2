<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('visit_batches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('visit_date');
            $table->foreignId('commercial_id')->constrained('commercials')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('visit_batches');
    }
}; 