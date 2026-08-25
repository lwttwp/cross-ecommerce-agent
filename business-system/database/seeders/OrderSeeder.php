<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\Product;
use Carbon\Carbon;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    /** 120 笔订单：近 6 个月、全状态覆盖、5 币种 */
    public function run(): void
    {
        $faker = Factory::create();
        $faker->seed(20260824);

        $customers = Customer::all(['id', 'currency']);
        $products = Product::where('status', 'on')->get(['id', 'sku', 'name', 'price']);
        $rates = ExchangeRate::pluck('rate_to_cny', 'currency')->all();
        $usdRate = (float) $rates['USD'];

        $distribution = array_merge(
            array_fill(0, 12, 'PENDING_PAYMENT'),
            array_fill(0, 18, 'PAID'),
            array_fill(0, 30, 'SHIPPED'),
            array_fill(0, 48, 'COMPLETED'),
            array_fill(0, 6, 'CANCELLED'),
            array_fill(0, 3, 'REFUNDING'),
            array_fill(0, 3, 'REFUNDED'),
        );
        shuffle($distribution);

        $spent = []; // customer_id => 累计消费（成交币种）
        $start = Carbon::parse('2026-02-01 08:00:00', 'Asia/Hong_Kong');

        DB::transaction(function () use ($faker, $customers, $products, $rates, $usdRate, $distribution, $start, &$spent) {
            $orderNoSeq = 1;
            foreach ($distribution as $status) {
                $customer = $customers->random();
                $currency = $customer->currency;
                $factor = $usdRate / (float) $rates[$currency]; // USD → 成交币种

                $picked = $faker->randomElements($products, random_int(1, 3));
                $items = [];
                $totalUsd = 0.0;
                foreach ($picked as $p) {
                    $qty = random_int(1, 2);
                    $items[] = ['product' => $p, 'quantity' => $qty];
                    $totalUsd += (float) $p->price * $qty;
                }
                $total = round($totalUsd * $factor, 2);

                $createdAt = (clone $start)->addMinutes($faker->numberBetween(0, 6 * 30 * 24 * 60));
                $orderNo = 'CE'.'2026'.str_pad((string) $orderNoSeq, 5, '0', STR_PAD_LEFT);
                $orderNoSeq++;

                $order = Order::create([
                    'order_no' => $orderNo,
                    'customer_id' => $customer->id,
                    'status' => OrderStatus::tryFrom($status),
                    'currency' => $currency,
                    'exchange_rate' => $rates[$currency],
                    'total_amount' => $total,
                    'paid_amount' => $total,
                    'shipping_address' => [
                        'recipient_name' => $faker->name,
                        'phone' => '+'.random_int(1, 99).$faker->numerify('##########'),
                        'country' => $customer->country,
                        'state' => $faker->state,
                        'city' => $faker->city,
                        'address_line1' => $faker->streetAddress,
                        'postal_code' => $faker->postcode,
                    ],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                foreach ($items as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'sku' => $item['product']->sku,
                        'name' => $item['product']->name,
                        'quantity' => $item['quantity'],
                        'unit_price' => round((float) $item['product']->price * $factor, 2),
                    ]);
                }

                $spent[$customer->id] = ($spent[$customer->id] ?? 0) + $total;
                $log = fn ($from, $to, $remark, $at, $op = 'agent') => OrderStatusLog::create([
                    'order_id' => $order->id,
                    'from_status' => $from,
                    'to_status' => $to,
                    'remark' => $remark,
                    'operator' => $op,
                    'created_at' => $at,
                ]);
                $log(null, 'PENDING_PAYMENT', '下单', $createdAt);

                if ($status === 'PENDING_PAYMENT') {
                    continue;
                }

                if ($status === 'CANCELLED') {
                    $cancelAt = (clone $createdAt)->addHours(2);
                    $log('PENDING_PAYMENT', 'CANCELLED', '取消订单，库存已返还', $cancelAt);

                    continue;
                }

                $paidAt = (clone $createdAt)->addMinutes(25 + random_int(0, 180));
                $order->update(['paid_at' => $paidAt]);
                $log('PENDING_PAYMENT', 'PAID', '支付成功', $paidAt, 'system');

                if ($status === 'PAID') {
                    continue;
                }

                $shippedAt = (clone $paidAt)->addDays(1);
                $trackingNo = 'SF'.$faker->numerify('##########');
                $order->update(['tracking_no' => $trackingNo, 'shipped_at' => $shippedAt]);
                $log('PAID', 'SHIPPED', "发货，运单号 {$trackingNo}", $shippedAt, 'admin');

                if ($status === 'SHIPPED') {
                    $order->update(['logistics_status' => $faker->randomElement(['IN_TRANSIT', 'IN_CUSTOMS', 'OUT_FOR_DELIVERY'])]);

                    continue;
                }

                // COMPLETED / REFUNDING / REFUNDED
                $completedAt = (clone $shippedAt)->addDays(7 + random_int(0, 5));
                $order->update(['logistics_status' => 'DELIVERED']);
                $log('SHIPPED', 'COMPLETED', '客户签收', $completedAt, 'system');
                $order->update(['completed_at' => $completedAt]);

                if ($status === 'REFUNDING') {
                    $refundAt = (clone $completedAt)->addDays(1);
                    $log('COMPLETED', 'REFUNDING', '提交退款申请，等待审批', $refundAt);

                    continue;
                }

                if ($status === 'REFUNDED') {
                    $refundAt = (clone $completedAt)->addDays(1);
                    $log('COMPLETED', 'REFUNDING', '提交退款申请，等待审批', $refundAt);
                    $approvedAt = (clone $refundAt)->addHours(6);
                    $log('REFUNDING', 'REFUNDED', '退款审批通过', $approvedAt, 'admin');
                }
            }

            foreach ($spent as $customerId => $amount) {
                Customer::where('id', $customerId)->update(['total_spent' => round($amount, 2)]);
            }
        });
    }
}
