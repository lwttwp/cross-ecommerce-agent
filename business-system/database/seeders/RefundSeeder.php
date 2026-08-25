<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RefundSeeder extends Seeder
{
    private const REASONS = ['尺寸不合适', '收到商品有瑕疵', '发错货了', '不再需要', '延迟发货太久'];

    public function run(): void
    {
        $adminId = User::where('role', 'admin')->value('id');

        DB::transaction(function () use ($adminId) {
            $seq = 1;

            // REFUNDING 订单 → 待审批退款单
            foreach (Order::where('status', 'REFUNDING')->get() as $order) {
                Refund::create([
                    'refund_no' => 'RF'.date('Ymd').str_pad((string) $seq++, 4, '0', STR_PAD_LEFT),
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'amount' => $order->paid_amount,
                    'currency' => $order->currency,
                    'reason' => self::REASONS[array_rand(self::REASONS)],
                    'status' => 'pending',
                    'order_status_before' => 'COMPLETED',
                    'created_at' => $order->updated_at,
                ]);
            }

            // REFUNDED 订单 → 已通过退款单
            foreach (Order::where('status', 'REFUNDED')->get() as $order) {
                Refund::create([
                    'refund_no' => 'RF'.date('Ymd').str_pad((string) $seq++, 4, '0', STR_PAD_LEFT),
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'amount' => $order->paid_amount,
                    'currency' => $order->currency,
                    'reason' => self::REASONS[array_rand(self::REASONS)],
                    'status' => 'approved',
                    'order_status_before' => 'COMPLETED',
                    'approver_id' => $adminId,
                    'approved_at' => $order->updated_at->addHours(6),
                    'created_at' => $order->updated_at,
                ]);
            }

            // 2 笔被驳回的退款（订单恢复 COMPLETED，补状态日志）
            foreach (Order::where('status', 'COMPLETED')->inRandomOrder()->take(2)->get() as $order) {
                $refund = Refund::create([
                    'refund_no' => 'RF'.date('Ymd').str_pad((string) $seq++, 4, '0', STR_PAD_LEFT),
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'amount' => $order->paid_amount,
                    'currency' => $order->currency,
                    'reason' => self::REASONS[array_rand(self::REASONS)],
                    'status' => 'rejected',
                    'order_status_before' => 'COMPLETED',
                    'approver_id' => $adminId,
                    'approved_at' => now(),
                    'created_at' => now(),
                ]);
                $at = now()->subDays(1);
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'from_status' => 'COMPLETED',
                    'to_status' => 'REFUNDING',
                    'remark' => "提交退款申请（{$refund->refund_no}），等待审批",
                    'operator' => 'agent',
                    'created_at' => $at,
                ]);
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'from_status' => 'REFUNDING',
                    'to_status' => 'COMPLETED',
                    'remark' => "退款审批驳回（{$refund->refund_no}），订单状态恢复",
                    'operator' => 'admin',
                    'created_at' => $at->addHours(5),
                ]);
            }
        });
    }
}
