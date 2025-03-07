<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        //check if table does not exist before creating it
        if (!Schema::hasTable('commercials')) {
            Schema::create('commercials', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('phone_number')->unique();
                $table->string('gender')->check("gender in ('male', 'female')");
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('commercials');
    }
}; 