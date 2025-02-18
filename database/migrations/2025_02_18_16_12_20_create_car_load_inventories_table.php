<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('car_load_inventories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('car_load_id')->constrained('car_loads')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->text("comment")->nullable();
            $table->boolean('closed')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('car_load_inventories');
    }
}; 