<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /** 40 个商品，5 个类目，基准价 USD，部分下架 */
    public function run(): void
    {
        $catalog = [
            'electronics' => [
                ['Wireless Earbuds Pro', 89.90, 0.06], ['Smart Watch S2', 129.00, 0.08],
                ['Bluetooth Speaker Mini', 39.90, 0.35], ['USB-C Charger 65W', 25.90, 0.12],
                ['LED Ring Light', 19.90, 0.30], ['Gaming Mouse M7', 49.90, 0.15],
                ['Mechanical Keyboard K87', 79.90, 0.90], ['Webcam 1080p', 45.00, 0.18],
                ['Power Bank 20000mAh', 35.50, 0.42], ['Foldable Phone Stand', 12.90, 0.09],
            ],
            'home' => [
                ['Aromatherapy Diffuser', 22.90, 0.28], ['LED Strip Lights 5m', 15.90, 0.20],
                ['Memory Foam Pillow', 29.90, 0.80], ['Electric Kettle 1.7L', 34.50, 0.95],
                ['Robot Vacuum V10', 189.00, 3.20], ['Blackout Curtains 2pcs', 41.00, 1.20],
                ['Smart Bulb A19 4-Pack', 24.90, 0.25], ['Air Purifier', 120.00, 4.50],
                ['Desk Lamp Dimmable', 27.50, 0.60], ['Storage Box Set', 18.90, 0.70],
            ],
            'beauty' => [
                ['Vitamin C Serum 30ml', 19.90, 0.08], ['Hyaluronic Acid Moisturizer', 24.50, 0.10],
                ['Sunscreen SPF50', 15.90, 0.07], ['Makeup Brush Set 12pcs', 21.90, 0.15],
                ['Facial Cleanser', 13.50, 0.12], ['Eye Cream', 18.00, 0.03],
                ['Lip Balm Set', 9.90, 0.05], ['Hair Dryer Ionic', 45.90, 0.55],
            ],
            'outdoor' => [
                ['Camping Tent 2-Person', 89.00, 2.40], ['Hiking Backpack 40L', 65.00, 1.10],
                ['Insulated Water Bottle 750ml', 22.50, 0.35], ['Headlamp Rechargeable', 16.90, 0.12],
                ['Camping Stove', 35.00, 0.85], ['Portable Hammock', 28.90, 0.60],
            ],
            'pets' => [
                ['Pet Grooming Kit', 26.90, 0.45], ['Automatic Pet Feeder', 79.00, 1.60],
                ['Cat Tree Tower', 119.00, 8.50], ['Dog Harness M', 18.50, 0.22],
                ['Pet Hair Remover', 12.90, 0.18], ['Interactive Cat Toy', 8.90, 0.10],
            ],
        ];

        $sku = 1000;
        foreach ($catalog as $category => $products) {
            foreach ($products as [$name, $price, $weight]) {
                $sku++;
                Product::updateOrCreate(['sku' => 'SKU-'.$sku], [
                    'name' => $name,
                    'description' => "{$name} — 跨境热销商品（类目：{$category}）",
                    'category' => $category,
                    'price' => $price,
                    'currency' => 'USD',
                    'stock' => random_int(0, 500),
                    'weight_kg' => $weight,
                    'status' => random_int(1, 100) <= 92 ? 'on' : 'off', // 8% 下架
                ]);
            }
        }
    }
}
