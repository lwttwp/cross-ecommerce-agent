<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 可观测开关
    |--------------------------------------------------------------------------
    | enabled=false 时：TraceRequest 中间件直接放行，不生成 trace、不落 trace_requests。
    | 线上排障遇到 trace 表写入异常时可临时关掉。
    */

    'enabled' => (bool) env('TRACE_ENABLED', true),

    // trace_requests / audit_logs 保留天数（P2 由定时任务清理，当前只做记录）
    'retention_days' => (int) env('TRACE_RETENTION_DAYS', 30),

];
