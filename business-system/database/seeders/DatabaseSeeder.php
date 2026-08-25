<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ExchangeRateSeeder::class,
            UserSeeder::class,
            CustomerSeeder::class,
            ProductSeeder::class,
            OrderSeeder::class,
            RefundSeeder::class,
        ]);
    }
}
