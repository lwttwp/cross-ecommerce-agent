<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'PENDING_PAYMENT';
    case Paid = 'PAID';
    case Shipped = 'SHIPPED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Refunding = 'REFUNDING';
    case Refunded = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => '待支付',
            self::Paid => '已支付',
            self::Shipped => '已发货',
            self::Completed => '已完成',
            self::Cancelled => '已取消',
            self::Refunding => '退款中',
            self::Refunded => '已退款',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
