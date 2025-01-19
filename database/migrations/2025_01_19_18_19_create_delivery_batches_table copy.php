<?php 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_batches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('delivery_date')->nullable();
            $table->foreignId('livreur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Add delivery_batch_id to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_batch_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeignIdFor('delivery_batch_id');
        });
        Schema::dropIfExists('delivery_batches');
    }
}; 