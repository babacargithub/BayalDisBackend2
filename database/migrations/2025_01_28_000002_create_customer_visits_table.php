<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('customer_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('visit_batch_id')->constrained()->onDelete('cascade');
            $table->dateTime('visit_planned_at');
            $table->dateTime('visited_at')->nullable();
            $table->string('status')->default('planned'); // planned, completed, cancelled
            $table->text('notes')->nullable();
            $table->boolean('resulted_in_sale')->default(false);
            $table->string('gps_coordinates')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_visits');
    }
}; 