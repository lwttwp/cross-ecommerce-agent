<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 写操作审计入口。
 *
 * 用法（控制器/Service 里的有副作用操作之后调用）：
 *   AuditLogger::log('refund.apply', 'refund', $refund->refund_no, ['order_no' => ..., 'amount' => ...]);
 *
 * 设计取舍：审计写失败绝不抛异常（不能因为日志表挂了导致退款失败），
 * 但要落一条 warning —— 否则「审计缺失」本身会变成无声事故。
 */
final class AuditLogger
{
    public static function log(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $payload = [],
    ): void {
        try {
            DB::table('audit_logs')->insert([
                'trace_id' => TraceContext::id(),
                'user_id' => TraceContext::userId(),
                'user_name' => TraceContext::userName(),
                'role' => TraceContext::role(),
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ip' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (QueryException|Throwable $e) {
            Log::warning('审计日志写入失败', [
                'action' => $action,
                'target' => $targetType.':'.$targetId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
