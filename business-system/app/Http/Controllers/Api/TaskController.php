<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Jobs\RunTaskJob;
use App\Models\Task;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 异步任务：落库排队（pending）+ 发布 RabbitMQ 消息，Worker 消费后执行报表/导出。
 */
class TaskController extends Controller
{
    /** 创建异步任务（报表/导出），立即返回 task_no */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(TaskType::values())],
            'params' => ['nullable', 'array'],
        ]);

        $task = Task::create([
            'task_no' => 'TSK'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'type' => $data['type'],
            'params' => $data['params'] ?? [],
            'status' => TaskStatus::Pending,
            'created_by' => null, // M3 接入用户体系后可填
        ]);

        // 发布到 RabbitMQ（连接默认队列 RABBITMQ_QUEUE=tasks），Worker 消费执行
        dispatch(new RunTaskJob($task->id));

        return ApiResponse::ok($this->format($task), '任务已创建，排队中');
    }

    /** 轮询任务状态/结果 */
    public function show(Request $request, string $taskNo): JsonResponse
    {
        $task = Task::where('task_no', $taskNo)->first();
        if (! $task) {
            return ApiResponse::fail(40406, '任务不存在', 404);
        }

        return ApiResponse::ok($this->format($task));
    }

    /** 下载任务产物（export:orders 的 CSV 等）
     *  默认：附件下载；加 ?inline=1：直接返回文件内容（agent 可直接读取）。 */
    public function download(Request $request, string $taskNo): SymfonyResponse
    {
        $task = Task::where('task_no', $taskNo)->first();
        if (! $task) {
            return ApiResponse::fail(40406, '任务不存在', 404);
        }
        if ($task->status !== TaskStatus::Success || ! $task->result_path) {
            return ApiResponse::fail(40908, '任务无产物可下载', 409);
        }
        if (! Storage::disk('local')->exists($task->result_path)) {
            return ApiResponse::fail(40408, '产物文件不存在', 404);
        }

        $name = basename($task->result_path);

        if ($request->boolean('inline')) {
            return response(Storage::disk('local')->get($task->result_path), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Storage::disk('local')->download($task->result_path, $name);
    }

    private function format(Task $task): array
    {
        return [
            'task_no' => $task->task_no,
            'type' => $task->type->value,
            'type_label' => $task->type->label(),
            'params' => $task->params,
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'result_summary' => $task->result_summary,
            'result_path' => $task->result_path,
            'error' => $task->error,
            'created_at' => $task->created_at?->toIso8601String(),
            'finished_at' => $task->finished_at?->toIso8601String(),
        ];
    }
}
