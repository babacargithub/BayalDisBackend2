<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Create default admin user
        User::create([
            'name' => 'Admin',
            'email' => 'pdggolobone@gmail.com',
            'password' => Hash::make('admin123'),
            'email_verified_at' => now(),
        ]);

        $this->call([
            CommercialSeeder::class,
            ProductSeeder::class,
        ]);
    }
}
