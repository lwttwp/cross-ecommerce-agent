<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 写操作审计记录。
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trace_id', 'user_id', 'user_name', 'role', 'action',
        'target_type', 'target_id', 'payload', 'ip', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
