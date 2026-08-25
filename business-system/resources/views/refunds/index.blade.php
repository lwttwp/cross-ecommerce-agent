@extends('layouts.admin')

@section('title', '退款审批')
@section('page_sub', 'human-in-the-loop：通过/驳回均写审计日志')

@section('content')
    <h1>💸 退款审批</h1>
    <div class="sub">共 {{ $refunds->total() }} 笔退款申请</div>

    @if (session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    <form class="filters" method="GET">
        <select name="status">
            <option value="">全部状态</option>
            @foreach (\App\Enums\RefundStatus::cases() as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <button type="submit">筛选</button>
        <a class="reset" href="{{ url('/admin/refunds') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>退款单号</th><th>订单号</th><th>金额</th><th>折合 CNY</th>
                <th>原因</th><th>状态</th><th>申请时间</th><th>操作</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($refunds as $refund)
                <tr>
                    <td><a href="{{ url('/admin/refunds/'.$refund->id) }}">{{ $refund->refund_no }}</a></td>
                    <td><a href="{{ url('/admin/orders/'.$refund->order?->order_no) }}">{{ $refund->order?->order_no }}</a></td>
                    <td>{{ number_format($refund->amount, 2) }} {{ $refund->currency }}</td>
                    <td>¥{{ number_format((float) $refund->amount * (float) ($refund->order?->exchange_rate ?? 1), 2) }}</td>
                    <td style="max-width: 220px;">{{ $refund->reason }}</td>
                    <td><span class="badge {{ $refund->status->value }}">{{ $refund->status->label() }}</span></td>
                    <td class="nowrap">{{ $refund->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        @if ($refund->status->value === 'pending')
                            <div style="display:flex; gap:6px;">
                                <form method="POST" action="{{ url("/admin/refunds/{$refund->id}/approve") }}">
                                    @csrf
                                    <button type="submit" style="background:#059669;">通过</button>
                                </form>
                                <form method="POST" action="{{ url("/admin/refunds/{$refund->id}/reject") }}">
                                    @csrf
                                    <button type="submit" style="background:#dc2626;">驳回</button>
                                </form>
                            </div>
                        @else
                            <small>{{ $refund->approved_at?->format('Y-m-d H:i') }}</small>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">没有符合条件的退款申请</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $refunds->links('pagination.custom') }}</div>
@endsection
