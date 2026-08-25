@extends('layouts.admin')

@section('title', '退款详情')
@section('page_sub', '退款单 ' . $refund->refund_no)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">💸 退款详情</h1>
        <a href="{{ url('/admin/refunds') }}" class="reset">← 返回列表</a>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">退款信息</h1>
            <table>
                <tr><th style="width:120px;">退款单号</th><td>{{ $refund->refund_no }}</td></tr>
                <tr><th>状态</th><td><span class="badge {{ $refund->status->value }}">{{ $refund->status->label() }}</span></td></tr>
                <tr><th>金额</th><td>{{ number_format($refund->amount, 2) }} {{ $refund->currency }}</td></tr>
                <tr><th>折合 CNY</th><td>¥{{ number_format((float) $refund->amount * (float) ($refund->order?->exchange_rate ?? 1), 2) }}</td></tr>
                <tr><th>退款原因</th><td style="white-space:normal;">{{ $refund->reason }}</td></tr>
                <tr><th>退款前订单状态</th><td>{{ $refund->order_status_before }}</td></tr>
                <tr><th>申请时间</th><td>{{ $refund->created_at?->format('Y-m-d H:i:s') }}</td></tr>
                <tr><th>审批时间</th><td>{{ $refund->approved_at?->format('Y-m-d H:i:s') ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">关联订单</h1>
            @if ($refund->order)
                <table>
                    <tr><th style="width:120px;">订单号</th><td><a href="{{ url('/admin/orders/'.$refund->order->order_no) }}">{{ $refund->order->order_no }}</a></td></tr>
                    <tr><th>订单状态</th><td><span class="badge {{ strtolower(str_replace('_','-',$refund->order->status->value)) }}">{{ $refund->order->status->label() }}</span></td></tr>
                    <tr><th>实付金额</th><td>{{ number_format($refund->order->paid_amount, 2) }} {{ $refund->order->currency }}</td></tr>
                    <tr><th>客户</th><td>
                        @if ($refund->order->customer)
                            <a href="{{ url('/admin/customers/'.$refund->order->customer->id) }}">{{ $refund->order->customer->name }}</a>
                            <br><small>{{ $refund->order->customer->email }}</small>
                        @else
                            -
                        @endif
                    </td></tr>
                </table>
            @else
                <div class="empty">关联订单缺失</div>
            @endif

            @if ($refund->status->value === 'pending')
                <div style="display:flex; gap:8px; margin-top:16px;">
                    <form method="POST" action="{{ url("/admin/refunds/{$refund->id}/approve") }}">
                        @csrf
                        <button type="submit" style="background:#059669;">通过审批</button>
                    </form>
                    <form method="POST" action="{{ url("/admin/refunds/{$refund->id}/reject") }}">
                        @csrf
                        <button type="submit" style="background:#dc2626;">驳回</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
@endsection
