@extends('layouts.admin')

@section('title', '申请退款')
@section('page_sub', '订单 ' . $order->order_no)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">💸 申请退款</h1>
        <a href="{{ url('/admin/orders/'.$order->order_no) }}" class="reset">← 返回订单详情</a>
    </div>

    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">订单信息</h1>
            <table>
                <tr><th style="width:120px;">订单号</th><td>{{ $order->order_no }}</td></tr>
                <tr><th>状态</th><td><span class="badge {{ strtolower(str_replace('_','-',$order->status->value)) }}">{{ $order->status->label() }}</span></td></tr>
                <tr><th>实付金额</th><td>{{ number_format($order->paid_amount, 2) }} {{ $order->currency }}</td></tr>
                <tr><th>折合 CNY</th><td>¥{{ number_format((float) $order->paid_amount * (float) $order->exchange_rate, 2) }}</td></tr>
                <tr><th>客户</th><td>{{ $order->customer?->name }} <small>({{ $order->customer?->email }})</small></td></tr>
            </table>

            @if (! in_array($order->status->value, ['PAID', 'SHIPPED', 'COMPLETED'], true))
                <div class="flash error" style="margin-top:14px;">当前状态不可申请退款（仅 PAID / SHIPPED / COMPLETED 可退）</div>
            @endif
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">退款信息</h1>
            <form method="POST" action="{{ url('/admin/orders/'.$order->order_no.'/refund') }}">
                @csrf
                <label style="display:block; font-size:13px; color:#374151; margin:10px 0 6px;">退款金额（{{ $order->currency }}，留空 = 全额）</label>
                <input type="number" name="amount" step="0.01" min="0.01"
                       value="{{ old('amount', $order->paid_amount) }}"
                       style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">

                <label style="display:block; font-size:13px; color:#374151; margin:14px 0 6px;">退款原因（必填）</label>
                <textarea name="reason" rows="3" required maxlength="500"
                          style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; resize:vertical;">{{ old('reason') }}</textarea>
                @error('reason')
                    <small style="color:#dc2626;">{{ $message }}</small>
                @enderror

                <button type="submit" style="margin-top:18px; width:100%; background:#dc2626;"
                        {{ in_array($order->status->value, ['PAID', 'SHIPPED', 'COMPLETED'], true) ? '' : 'disabled' }}>
                    提交退款申请（进入人工审批）
                </button>
            </form>
        </div>
    </div>
@endsection
