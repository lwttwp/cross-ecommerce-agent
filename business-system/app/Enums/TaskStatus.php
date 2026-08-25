<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '排队中',
            self::Running => '执行中',
            self::Success => '已完成',
            self::Failed => '失败',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
