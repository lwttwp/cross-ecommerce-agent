<?php

namespace App\Http\Controllers\Web;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Request $request): View
    {
        $query = Task::query()->orderByDesc('created_at');

        $query->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $tasks = $query->paginate(15)->withQueryString();

        return view('admin.tasks', [
            'tasks' => $tasks,
            'types' => TaskType::cases(),
            'statuses' => TaskStatus::cases(),
        ]);
    }

    /** 下载任务产物（CSV） */
    public function download(string $taskNo)
    {
        $task = Task::where('task_no', $taskNo)->first();
        abort_unless($task && $task->status === TaskStatus::Success && $task->result_path, 404, '任务无产物');
        abort_unless(Storage::disk('local')->exists($task->result_path), 404, '产物文件不存在');

        return Storage::disk('local')->download($task->result_path, basename($task->result_path));
    }

    /** 任务详情 */
    public function show(string $taskNo): View
    {
        $task = Task::where('task_no', $taskNo)->firstOrFail();

        return view('admin.tasks_show', ['task' => $task]);
    }
}
