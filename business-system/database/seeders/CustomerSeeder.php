<?php

namespace Database\Seeders;

use App\Models\Customer;
use Faker\Factory;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /** 20 位海外客户，覆盖 8 个国家 */
    public function run(): void
    {
        $faker = Factory::create();
        $faker->seed(20260824);

        $countries = [
            'US' => 'USD', 'GB' => 'GBP', 'DE' => 'EUR', 'JP' => 'JPY',
            'SG' => 'SGD', 'CA' => 'USD', 'AU' => 'USD', 'FR' => 'EUR',
        ];

        for ($i = 0; $i < 20; $i++) {
            $country = array_rand($countries);
            Customer::updateOrCreate(['email' => $faker->unique()->safeEmail], [
                'name' => $faker->name,
                'phone' => '+'.random_int(1, 99).$faker->numerify('##########'),
                'country' => $country,
                'currency' => $countries[$country],
                'total_spent' => 0,
            ]);
        }
    }
}
