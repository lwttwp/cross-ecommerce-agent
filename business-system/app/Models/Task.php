<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = [
        'task_no', 'type', 'params', 'status', 'result_summary',
        'result_path', 'error', 'created_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'params' => 'array',
            'result_summary' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
