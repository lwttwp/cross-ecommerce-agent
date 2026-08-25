@extends('layouts.admin')

@section('title', '异步任务')
@section('page_sub', '报表/导出任务队列（RabbitMQ Worker 消费）')

@section('content')
    <h1>⚙️ 异步任务</h1>
    <div class="sub">共 {{ $tasks->total() }} 个任务</div>

    <form class="filters" method="GET">
        <select name="type">
            <option value="">全部类型</option>
            @foreach ($types as $t)
                <option value="{{ $t->value }}" @selected(request('type') === $t->value)>{{ $t->label() }}</option>
            @endforeach
        </select>
        <select name="status">
            <option value="">全部状态</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <button type="submit">筛选</button>
        <a class="reset" href="{{ url('/admin/tasks') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>任务号</th><th>类型</th><th>状态</th><th>结果摘要</th><th>产物</th><th>创建时间</th><th>完成时间</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($tasks as $task)
                <tr>
                    <td><a href="{{ url('/admin/tasks/'.$task->task_no) }}">{{ $task->task_no }}</a></td>
                    <td>{{ $task->type->label() }}</td>
                    <td><span class="badge {{ $task->status->value }}">{{ $task->status->label() }}</span></td>
                    <td style="max-width:280px;">
                        @if ($task->error)
                            <small style="color:#dc2626;">{{ $task->error }}</small>
                        @elseif ($task->result_summary)
                            <small>{{ json_encode($task->result_summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</small>
                        @else
                            <small>-</small>
                        @endif
                    </td>
                    <td>
                        @if ($task->status->value === 'success' && $task->result_path)
                            <a href="{{ url('/admin/tasks/'.$task->task_no.'/download') }}">下载</a>
                        @else
                            <small>-</small>
                        @endif
                    </td>
                    <td class="nowrap">{{ $task->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="nowrap">{{ $task->finished_at?->format('Y-m-d H:i') ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">暂无任务</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $tasks->links('pagination.custom') }}</div>
@endsection
