@extends('layouts.admin')

@section('title', '全链路追踪')
@section('page_sub', '按 trace_id 串联 chat → agent → API → 队列 → 事件')

@section('content')
    <h1>🔗 全链路追踪</h1>
    <div class="sub">近 24 小时：{{ $summary['traces'] }} 条链路 / {{ $summary['spans'] }} 个 span / {{ $summary['errors'] }} 个错误 / {{ $summary['audits'] }} 条审计</div>

    <form class="filters" method="GET">
        <input type="text" name="trace_id" value="{{ request('trace_id') }}" placeholder="trace_id（16 位十六进制）">
        <select name="channel">
            <option value="">全部渠道</option>
            @foreach (['http' => 'http 请求', 'agent' => 'Agent 对话', 'queue' => '队列任务', 'event' => '领域事件'] as $v => $label)
                <option value="{{ $v }}" @selected(request('channel') === $v)>{{ $label }}</option>
            @endforeach
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;">
            <input type="checkbox" name="only_error" value="1" @checked(request('only_error'))> 只看错误
        </label>
        <input type="date" name="date_from" value="{{ request('date_from') }}" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;">
        <input type="date" name="date_to" value="{{ request('date_to') }}" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;">
        <button type="submit">筛选</button>
        <a class="reset" href="{{ url('/admin/traces') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>trace_id</th><th>span</th><th>渠道</th><th>状态</th><th>耗时</th>
                <th>操作人</th><th>路径 / 路由</th><th>时间</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($spans as $span)
                <tr>
                    <td class="nowrap"><a href="{{ url('/admin/traces/'.$span->trace_id) }}">{{ $span->trace_id }}</a></td>
                    <td class="nowrap">{{ $span->span }}</td>
                    <td><span class="badge {{ $span->channel === 'http' ? 'shipped' : ($span->channel === 'agent' ? 'completed' : 'running') }}">{{ $span->channel }}</span></td>
                    <td class="nowrap">
                        @if ($span->status !== null && $span->status >= 400)
                            <span class="badge failed">{{ $span->status }}</span>
                        @else
                            {{ $span->status ?? '—' }}
                        @endif
                    </td>
                    <td class="nowrap">{{ $span->duration_ms !== null ? $span->duration_ms.' ms' : '—' }}</td>
                    <td class="nowrap">
                        {{ $span->user_name ?: '—' }}
                        @if ($span->role)<small>（{{ $span->role }}）</small>@endif
                    </td>
                    <td style="max-width: 380px; word-break: break-all;">
                        <small>{{ $span->method }} {{ $span->path ?: $span->route }}</small>
                    </td>
                    <td class="nowrap">{{ $span->created_at?->timezone('Asia/Shanghai')->format('m-d H:i:s') }}</td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty">暂无链路记录；先在 /chat 发一条消息，或触发一次后台操作</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $spans->links('pagination.custom') }}</div>
@endsection
