@extends('layouts.admin')

@section('title', '链路详情')
@section('page_sub', '同一 trace_id 的所有 span 与审计')

@section('content')
    <h1>🔗 链路 {{ $traceId }}</h1>
    <div class="sub">共 {{ $spans->count() }} 个 span、{{ $audits->count() }} 条审计记录</div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>#</th><th>span</th><th>渠道</th><th>状态</th><th>耗时</th>
                <th>操作人</th><th>路径</th><th>附加信息</th><th>时间</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($spans as $i => $span)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="nowrap">{{ $span->span }}</td>
                    <td>{{ $span->channel }}</td>
                    <td class="nowrap">
                        @if ($span->status !== null && $span->status >= 400)
                            <span class="badge failed">{{ $span->status }}</span>
                        @else
                            {{ $span->status ?? '—' }}
                        @endif
                    </td>
                    <td class="nowrap">{{ $span->duration_ms !== null ? $span->duration_ms.' ms' : '—' }}</td>
                    <td class="nowrap">{{ $span->user_name ?: '—' }} @if ($span->role)<small>（{{ $span->role }}）</small>@endif</td>
                    <td style="word-break: break-all;"><small>{{ $span->method }} {{ $span->path ?: $span->route }}</small></td>
                    <td style="max-width: 320px; word-break: break-all;">
                        <small>{{ $span->meta ? json_encode($span->meta, JSON_UNESCAPED_UNICODE) : '—' }}</small>
                    </td>
                    <td class="nowrap">{{ $span->created_at?->timezone('Asia/Shanghai')->format('H:i:s') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="font-size:15px;margin:0 0 10px;">审计记录</h2>
        @if ($audits->isEmpty())
            <div class="empty">该链路没有写操作</div>
        @else
            <table>
                <thead>
                <tr><th>动作</th><th>对象</th><th>操作人</th><th>参数</th><th>时间</th></tr>
                </thead>
                <tbody>
                @foreach ($audits as $log)
                    <tr>
                        <td class="nowrap">{{ $log->action }}</td>
                        <td class="nowrap">{{ $log->target_type }}:{{ $log->target_id }}</td>
                        <td class="nowrap">{{ $log->user_name ?: '—' }}</td>
                        <td style="max-width: 420px; word-break: break-all;"><small>{{ $log->payload ? json_encode($log->payload, JSON_UNESCAPED_UNICODE) : '—' }}</small></td>
                        <td class="nowrap">{{ $log->created_at?->timezone('Asia/Shanghai')->format('H:i:s') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <a class="reset" href="{{ url('/admin/traces') }}">← 返回链路列表</a>
@endsection
