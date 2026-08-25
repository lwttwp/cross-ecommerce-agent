<?php

namespace App\Enums;

enum TaskType: string
{
    case MonthlySales = 'report:monthly_sales';
    case RefundRate = 'report:refund_rate';
    case ExportOrders = 'export:orders';

    public function label(): string
    {
        return match ($this) {
            self::MonthlySales => '月度销售报表',
            self::RefundRate => '退款率报表',
            self::ExportOrders => '订单导出',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
