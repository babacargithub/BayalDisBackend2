<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
        });
        $relationships = DB::table('customer_sectors')
            ->select('customer_id', 'sector_id')
            ->orderBy('created_at', 'desc')
            ->get();

        $processedCustomers = [];

        foreach ($relationships as $rel) {
            // Only process each customer once (their most recent sector)
            if (!in_array($rel->customer_id, $processedCustomers)) {
                DB::table('customers')
                    ->where('id', $rel->customer_id)
                    ->update(['sector_id' => $rel->sector_id]);

                $processedCustomers[] = $rel->customer_id;
            }
        }
        Schema::dropIfExists('customer_sectors');

    }

    public function down()
    {



        if (!Schema::hasTable('customer_sectors')) {
            Schema::create('customer_sectors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });
        }
        // Get all customers with sectors and create relationships
        $customers = DB::table('customers')
            ->whereNotNull('sector_id')
            ->select('id', 'sector_id')
            ->get();

        foreach ($customers as $customer) {
            DB::table('customer_sectors')->insert([
                'customer_id' => $customer->id,
                'sector_id' => $customer->sector_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Schema::table('customers', function (Blueprint $table) {
        $table->dropConstrainedForeignId('sector_id');
    });
    }
}; 