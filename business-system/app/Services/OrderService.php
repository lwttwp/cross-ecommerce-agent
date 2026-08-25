<?php

namespace App\Services;

use App\Enums\LogisticsStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 订单核心业务：状态机流转、库存事务、退款审批（human-in-the-loop 的业务侧）。
 */
class OrderService
{
    /** 订单状态机：合法流转表 */
    private const TRANSITIONS = [
        'PENDING_PAYMENT' => ['PAID', 'CANCELLED'],
        'PAID' => ['SHIPPED', 'REFUNDING'],
        'SHIPPED' => ['COMPLETED', 'REFUNDING'],
        'COMPLETED' => ['REFUNDING'],
        'REFUNDING' => ['REFUNDED', 'PENDING_PAYMENT', 'PAID', 'SHIPPED', 'COMPLETED'],
        'CANCELLED' => [],
        'REFUNDED' => [],
    ];

    /** 可修改收货地址的状态 */
    private const ADDRESS_EDITABLE = ['PENDING_PAYMENT', 'PAID'];

    /** 可申请退款的状态 */
    private const REFUNDABLE = ['PAID', 'SHIPPED', 'COMPLETED'];

    public static function generateOrderNo(): string
    {
        do {
            $no = 'CE'.date('Ymd').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (Order::where('order_no', $no)->exists());

        return $no;
    }

    public static function generateRefundNo(): string
    {
        do {
            $no = 'RF'.date('Ymd').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (Refund::where('refund_no', $no)->exists());

        return $no;
    }

    /**
     * 下单：校验商品与库存 → 事务扣减库存 → 生成订单。
     * 商品基准价为 USD，成交币种金额按汇率换算，订单保存汇率快照。
     */
    public function create(array $data): Order
    {
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new BusinessException('订单至少需要一个商品', 40001);
        }

        return DB::transaction(function () use ($data, $items) {
            $currency = strtoupper((string) ($data['currency'] ?? 'USD'));
            $rate = ExchangeRate::where('currency', $currency)->value('rate_to_cny');
            if ($rate === null) {
                throw new BusinessException("不支持的币种: {$currency}", 40001);
            }
            $usdRate = (float) ExchangeRate::where('currency', 'USD')->value('rate_to_cny');
            $factor = $usdRate / (float) $rate; // USD → 成交币种

            $total = 0.0;
            $orderItems = [];
            foreach ($items as $item) {
                $product = Product::where('sku', $item['sku'])->lockForUpdate()->first();
                if (! $product || $product->status !== 'on') {
                    throw new BusinessException("商品 {$item['sku']} 不存在或已下架", 40001);
                }
                $qty = (int) ($item['quantity'] ?? 1);
                if ($qty <= 0 || $product->stock < $qty) {
                    throw new BusinessException("商品 {$product->sku} 库存不足（剩余 {$product->stock}）", 40906);
                }
                $product->decrement('stock', $qty);
                $unitPrice = round((float) $product->price * $factor, 2);
                $total += $unitPrice * $qty;
                $orderItems[] = [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                ];
            }

            $total = round($total, 2);
            $order = Order::create([
                'order_no' => self::generateOrderNo(),
                'customer_id' => (int) $data['customer_id'],
                'status' => OrderStatus::PendingPayment,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'total_amount' => $total,
                'paid_amount' => $total,
                'shipping_address' => $data['shipping_address'],
            ]);
            foreach ($orderItems as $oi) {
                $order->items()->create($oi);
            }
            $this->logStatus($order, null, OrderStatus::PendingPayment->value, '下单', 'agent');

            return $order->fresh(['items']);
        });
    }

    /** 支付（演示/后台触发） */
    public function pay(Order $order, string $operator = 'system'): Order
    {
        return DB::transaction(function () use ($order, $operator) {
            $order->update(['paid_at' => now()]);

            return $this->transition($order, OrderStatus::Paid, '支付成功', $operator);
        });
    }

    /** 发货 */
    public function ship(Order $order, string $trackingNo, string $operator = 'admin'): Order
    {
        return DB::transaction(function () use ($order, $trackingNo, $operator) {
            $order->update([
                'tracking_no' => $trackingNo,
                'logistics_status' => LogisticsStatus::Pending,
                'shipped_at' => now(),
            ]);

            return $this->transition($order, OrderStatus::Shipped, "发货，运单号 {$trackingNo}", $operator);
        });
    }

    /** 签收完成 */
    public function markCompleted(Order $order, string $operator = 'system'): Order
    {
        return DB::transaction(function () use ($order, $operator) {
            $order->update([
                'logistics_status' => LogisticsStatus::Delivered,
                'completed_at' => now(),
            ]);

            return $this->transition($order, OrderStatus::Completed, '客户签收', $operator);
        });
    }

    /** 取消订单（仅待支付，返还库存） */
    public function cancel(Order $order, string $operator = 'agent'): Order
    {
        return DB::transaction(function () use ($order, $operator) {
            foreach ($order->items as $item) {
                Product::where('id', $item->product_id)->increment('stock', $item->quantity);
            }

            return $this->transition($order, OrderStatus::Cancelled, '取消订单，库存已返还', $operator);
        });
    }

    /** 修改收货地址（仅未发货） */
    public function updateAddress(Order $order, array $address, string $operator = 'agent'): Order
    {
        if (! in_array($order->status->value, self::ADDRESS_EDITABLE, true)) {
            throw new BusinessException('当前订单状态不允许修改收货地址', 40901);
        }
        $old = $order->shipping_address;
        $order->update(['shipping_address' => $address]);
        $this->logStatus(
            $order,
            $order->status->value,
            $order->status->value,
            '修改收货地址：'.($old['country'] ?? '').' → '.($address['country'] ?? ''),
            $operator
        );

        return $order->fresh();
    }

    /** 申请退款（进入退款中，等待人工审批） */
    public function applyRefund(Order $order, string $reason, ?float $amount = null, string $operator = 'agent'): Refund
    {
        if (! in_array($order->status->value, self::REFUNDABLE, true)) {
            throw new BusinessException('当前订单状态不可申请退款', 40902);
        }
        if (Refund::where('order_id', $order->id)->where('status', 'pending')->exists()) {
            throw new BusinessException('该订单已有待审批的退款申请', 40903);
        }
        $amount = $amount ?? (float) $order->paid_amount;
        if ($amount <= 0 || $amount > (float) $order->paid_amount) {
            throw new BusinessException('退款金额不能超过实付金额', 40904);
        }

        return DB::transaction(function () use ($order, $reason, $amount, $operator) {
            $refund = Refund::create([
                'refund_no' => self::generateRefundNo(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'amount' => $amount,
                'currency' => $order->currency,
                'reason' => $reason,
                'status' => 'pending',
                'order_status_before' => $order->status->value,
            ]);
            $this->transition($order, OrderStatus::Refunding, "提交退款申请（{$refund->refund_no}），等待审批", $operator);

            return $refund->fresh();
        });
    }

    /** 审批通过退款 → 订单已退款 */
    public function approveRefund(Refund $refund, string $operator = 'admin'): Refund
    {
        return DB::transaction(function () use ($refund, $operator) {
            $this->assertPending($refund);
            $order = $refund->order;
            if ($order->status !== OrderStatus::Refunding) {
                throw new BusinessException('订单当前状态不是退款中，无法审批', 40905);
            }
            $refund->update([
                'status' => 'approved',
                'approver_id' => User::where('role', 'admin')->value('id'),
                'approved_at' => now(),
            ]);
            $this->transition($order, OrderStatus::Refunded, "退款审批通过（{$refund->refund_no}）", $operator);

            return $refund->fresh();
        });
    }

    /** 审批驳回退款 → 订单恢复原状态 */
    public function rejectRefund(Refund $refund, string $operator = 'admin'): Refund
    {
        return DB::transaction(function () use ($refund, $operator) {
            $this->assertPending($refund);
            $order = $refund->order;
            $restore = OrderStatus::tryFrom($refund->order_status_before) ?? OrderStatus::Paid;
            $refund->update([
                'status' => 'rejected',
                'approver_id' => User::where('role', 'admin')->value('id'),
                'approved_at' => now(),
            ]);
            $this->transition($order, $restore, "退款审批驳回（{$refund->refund_no}），订单状态恢复", $operator);

            return $refund->fresh();
        });
    }

    /** 模拟物流轨迹时间线 */
    public function trackingTimeline(Order $order): array
    {
        if (! $order->tracking_no) {
            return [];
        }
        $start = $order->shipped_at ?? now();
        $offsetHours = [
            LogisticsStatus::Pending->value => 0,
            LogisticsStatus::InTransit->value => 24,
            LogisticsStatus::InCustoms->value => 72,
            LogisticsStatus::OutForDelivery->value => 120,
            LogisticsStatus::Delivered->value => 168,
        ];
        $current = $order->logistics_status ?? LogisticsStatus::Pending;

        $timeline = [];
        foreach (LogisticsStatus::cases() as $status) {
            $timeline[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'time' => $start->copy()->addHours($offsetHours[$status->value])->toIso8601String(),
                'current' => $status === $current,
            ];
            if ($status === $current) {
                break;
            }
        }

        return $timeline;
    }

    /** 状态机流转 + 审计日志 */
    public function transition(Order $order, OrderStatus $to, string $remark, string $operator): Order
    {
        $from = $order->status;
        $allowed = self::TRANSITIONS[$from->value] ?? [];
        if ($to->value === $from->value) {
            return $order;
        }
        if (! in_array($to->value, $allowed, true)) {
            throw new BusinessException("订单状态不允许从 {$from->value} 变更为 {$to->value}", 40901);
        }
        $order->update(['status' => $to]);
        $this->logStatus($order, $from->value, $to->value, $remark, $operator);

        return $order;
    }

    public function logStatus(Order $order, ?string $from, ?string $to, string $remark, string $operator): void
    {
        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'remark' => $remark,
            'operator' => $operator,
        ]);
    }

    private function assertPending(Refund $refund): void
    {
        if ($refund->status->value !== 'pending') {
            throw new BusinessException('退款单不是待审批状态', 40905);
        }
    }
}
