<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待审批',
            self::Approved => '已通过',
            self::Rejected => '已驳回',
        };
    }
}
