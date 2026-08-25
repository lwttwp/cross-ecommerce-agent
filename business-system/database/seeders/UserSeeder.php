<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Admin',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        User::updateOrCreate(['email' => 'agent@example.com'], [
            'name' => 'Agent Bot',
            'password' => Hash::make('agent123'),
            'role' => 'agent',
        ]);
    }
}
