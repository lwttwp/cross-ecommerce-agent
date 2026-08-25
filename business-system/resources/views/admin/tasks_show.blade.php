@extends('layouts.admin')

@section('title', '任务详情')
@section('page_sub', '任务 ' . $task->task_no)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">⚙️ 任务详情</h1>
        <div style="display:flex; gap:10px; align-items:center;">
            @if ($task->status->value === 'success' && $task->result_path)
                <a href="{{ url('/admin/tasks/'.$task->task_no.'/download') }}" style="font-size:13px; color:#2563eb;">⬇ 下载产物</a>
            @endif
            <a href="{{ url('/admin/tasks') }}" class="reset">← 返回列表</a>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">任务信息</h1>
            <table>
                <tr><th style="width:120px;">任务号</th><td>{{ $task->task_no }}</td></tr>
                <tr><th>类型</th><td>{{ $task->type->label() }}</td></tr>
                <tr><th>状态</th><td><span class="badge {{ $task->status->value }}">{{ $task->status->label() }}</span></td></tr>
                <tr><th>创建时间</th><td>{{ $task->created_at?->format('Y-m-d H:i:s') }}</td></tr>
                <tr><th>完成时间</th><td>{{ $task->finished_at?->format('Y-m-d H:i:s') ?? '-' }}</td></tr>
                <tr><th>产物路径</th><td>{{ $task->result_path ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">任务参数</h1>
            @if ($task->params)
                <pre style="background:#f9fafb; border-radius:8px; padding:12px; font-size:12.5px; margin:0; overflow:auto;">{{ json_encode($task->params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
                <div class="empty">无参数</div>
            @endif
        </div>
    </div>

    <div class="card">
        <h1 style="font-size:15px; margin-bottom:12px;">执行结果</h1>
        @if ($task->error)
            <div class="flash error">{{ $task->error }}</div>
        @elseif ($task->result_summary)
            <pre style="background:#f9fafb; border-radius:8px; padding:12px; font-size:12.5px; margin:0; overflow:auto;">{{ json_encode($task->result_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
            <div class="empty">任务尚未执行完成</div>
        @endif
    </div>
@endsection
