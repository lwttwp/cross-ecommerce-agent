<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 异步任务执行（M2）：消费 RabbitMQ 队列，按任务类型生成报表/导出。
 * 状态流转：pending → running → success | failed。
 */
class RunTaskJob implements ShouldQueue
{
    use Queueable;

    /** 失败重试次数 */
    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(private readonly int $taskId) {}

    public function handle(ReportService $reports): void
    {
        $task = Task::find($this->taskId);
        if (! $task) {
            Log::warning('RunTaskJob: 任务不存在', ['task_id' => $this->taskId]);

            return;
        }

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

            throw $e; // 触发 Laravel 队列重试机制（tries=3）
        }
    }
}
