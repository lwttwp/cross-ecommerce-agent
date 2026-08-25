<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /** 客户详情 + 消费统计（手机号脱敏） */
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = Customer::with('orders:id,customer_id,status,currency,exchange_rate,paid_amount')->find($id);
        if (! $customer) {
            return ApiResponse::fail(40404, '客户不存在', 404);
        }

        $orders = $customer->orders;
        $spentCny = 0.0;
        $orderCount = 0;
        $refundedCount = 0;
        $paidStatuses = ['PAID', 'SHIPPED', 'COMPLETED', 'REFUNDING', 'REFUNDED'];
        foreach ($orders as $order) {
            $orderCount++;
            if (! in_array($order->status->value, $paidStatuses, true)) {
                continue; // 未支付/已取消：不计入消费金额
            }
            $spentCny += round((float) $order->paid_amount * (float) $order->exchange_rate, 2);
            if (in_array($order->status->value, ['REFUNDED', 'REFUNDING'], true)) {
                $refundedCount++;
            }
        }

        return ApiResponse::ok([
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $this->maskPhone($customer->phone),
            'country' => $customer->country,
            'currency' => $customer->currency,
            'stats' => [
                'order_count' => $orderCount,
                'total_spent_cny' => round($spentCny, 2),
                'refund_related_count' => $refundedCount,
            ],
            'created_at' => $customer->created_at?->toIso8601String(),
        ]);
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }
}
