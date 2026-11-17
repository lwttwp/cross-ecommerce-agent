<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Services\ReportService;
use App\Support\TraceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 异步任务执行（M2）：消费 RabbitMQ 队列，按任务类型生成报表/导出。
 * 状态流转：pending → running → success | failed。
 *
 * 可观测：job 携带派发时的 trace_id，worker 侧落一行 channel=queue 的 span，
 * 这样「用户点导出 → 队列执行 → 生成文件」仍是同一条链路。
 */
class RunTaskJob implements ShouldQueue
{
    use Queueable;

    /** 失败重试次数 */
    public int $tries = 3;

    public int $backoff = 5;

    private int $taskId;

    /** 派发方的 trace_id；可空，兼容升级前已在队列里的消息 */
    public ?string $traceId = null;

    public function __construct(int $taskId, ?string $traceId = null)
    {
        $this->taskId = $taskId;
        $this->traceId = $traceId;
    }

    public function handle(ReportService $reports): void
    {
        // 队列 worker 是常驻进程，必须清理上一个 job 的上下文
        TraceContext::reset();
        TraceContext::setTraceId($this->traceId);

        $startedAt = hrtime(true);
        $task = Task::find($this->taskId);
        if (! $task) {
            Log::warning('RunTaskJob: 任务不存在', ['task_id' => $this->taskId]);

            return;
        }

        // 任务创建人即操作人（Agent 透传的员工 id），便于链路里显示「谁导的」
        if ($task->created_by !== null) {
            $creator = $task->creator;
            TraceContext::setUserFromModel($creator);
        }

        Log::withContext([
            'trace_id' => TraceContext::id(),
            'user_id' => TraceContext::userId(),
        ]);

        $task->update(['status' => TaskStatus::Running]);
        Log::info('RunTaskJob: 开始执行', ['task_no' => $task->task_no, 'type' => $task->type->value]);

        try {
            $result = match ($task->type) {
                TaskType::MonthlySales => $reports->monthlySales($task->params),
                TaskType::RefundRate => $reports->refundRate($task->params),
                TaskType::ExportOrders => $reports->exportOrders($task->params),
            };

            $task->update([
                'status' => TaskStatus::Success,
                'result_summary' => $result['summary'],
                'result_path' => $result['path'],
                'error' => null,
                'finished_at' => now(),
            ]);
            Log::info('RunTaskJob: 执行成功', ['task_no' => $task->task_no]);
            $this->recordSpan($task, 200, $startedAt);
        } catch (Throwable $e) {
            Log::error('RunTaskJob: 执行失败', [
                'task_no' => $task->task_no,
                'error' => $e->getMessage(),
            ]);

            $task->update([
                'status' => TaskStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->recordSpan($task, 500, $startedAt, $e->getMessage());

            throw $e; // 触发 Laravel 队列重试机制（tries=3）
        }
    }

    private function recordSpan(Task $task, int $status, int $startedAtNs, ?string $error = null): void
    {
        if (! config('observability.enabled')) {
            return;
        }

        try {
            DB::table('trace_requests')->insert([
                'trace_id' => TraceContext::id(),
                'span' => 'queue.job',
                'channel' => 'queue',
                'method' => null,
                'path' => 'job:RunTaskJob',
                'route' => (string) $task->type->value,
                'status' => $status,
                'duration_ms' => (int) round((hrtime(true) - $startedAtNs) / 1_000_000),
                'user_id' => TraceContext::userId(),
                'user_name' => TraceContext::userName(),
                'role' => TraceContext::role(),
                'ip' => null,
                'session_id' => null,
                'meta' => json_encode(array_filter([
                    'task_no' => $task->task_no,
                    'error' => $error,
                    'trace_origin' => TraceContext::origin(),
                ]), JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('queue span 落库失败', ['error' => $e->getMessage()]);
        }
    }
}
