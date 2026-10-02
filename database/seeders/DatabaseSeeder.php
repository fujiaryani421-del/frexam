<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Akun awal dipakai untuk mengakses manajemen pengguna setelah instalasi baru.
        User::firstOrCreate(
            ['username' => 'admin'],
            ['name' => 'Administrator', 'role' => 'admin', 'is_active' => true, 'password' => Hash::make('admin123')]
        );
    }
}
