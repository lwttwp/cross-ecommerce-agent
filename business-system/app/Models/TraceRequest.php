<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 链路 span（只读模型，写入在中间件与 Agent 上报接口里批量 insert）。
 */
class TraceRequest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trace_id', 'span', 'channel', 'method', 'path', 'route', 'status',
        'duration_ms', 'user_id', 'user_name', 'role', 'ip', 'session_id', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
