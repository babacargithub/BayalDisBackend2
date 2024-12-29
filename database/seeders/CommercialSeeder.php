<?php

namespace Database\Seeders;

use App\Models\Commercial;
use Illuminate\Database\Seeder;

class CommercialSeeder extends Seeder
{
    public function run()
    {
        Commercial::create([
            'name' => 'Jean Dupont',
            'phone_number' => '221777777777',
            'gender' => 'male',
        ]);
    }
} 