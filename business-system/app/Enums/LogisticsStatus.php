<?php

namespace App\Enums;

enum LogisticsStatus: string
{
    case Pending = 'PENDING';               // 待揽收
    case InTransit = 'IN_TRANSIT';          // 国际运输中
    case InCustoms = 'IN_CUSTOMS';          // 清关中
    case OutForDelivery = 'OUT_FOR_DELIVERY'; // 派送中
    case Delivered = 'DELIVERED';           // 已签收

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待揽收',
            self::InTransit => '国际运输中',
            self::InCustoms => '清关中',
            self::OutForDelivery => '派送中',
            self::Delivered => '已签收',
        };
    }
}
