<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    /** 币种 → CNY 汇率（快照基准） */
    public function run(): void
    {
        $rates = [
            'USD' => 7.12,
            'EUR' => 7.72,
            'GBP' => 9.05,
            'JPY' => 0.048,
            'SGD' => 5.28,
        ];

        foreach ($rates as $currency => $rate) {
            ExchangeRate::updateOrCreate(
                ['currency' => $currency],
                ['rate_to_cny' => $rate]
            );
        }
    }
}
