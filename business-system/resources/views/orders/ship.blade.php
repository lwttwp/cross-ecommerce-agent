@extends('layouts.admin')

@section('title', '订单发货')
@section('page_sub', '订单 ' . $order->order_no)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">📦 订单发货</h1>
        <a href="{{ url('/admin/orders') }}" class="reset">← 返回列表</a>
    </div>

    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">订单信息</h1>
            <table>
                <tr><th style="width:120px;">订单号</th><td><a href="{{ url('/admin/orders/'.$order->order_no) }}">{{ $order->order_no }}</a></td></tr>
                <tr><th>状态</th><td><span class="badge {{ strtolower(str_replace('_','-',$order->status->value)) }}">{{ $order->status->label() }}</span></td></tr>
                <tr><th>实付金额</th><td>{{ number_format($order->paid_amount, 2) }} {{ $order->currency }}</td></tr>
                <tr><th>客户</th><td>{{ $order->customer?->name }} <small>({{ $order->customer?->email }})</small></td></tr>
                <tr><th>收货地址</th><td style="white-space:normal;">
                    @if ($order->shipping_address)
                        {{ $order->shipping_address['recipient_name'] ?? '' }} · {{ $order->shipping_address['phone'] ?? '' }}<br>
                        {{ $order->shipping_address['address_line1'] ?? '' }}, {{ $order->shipping_address['city'] ?? '' }}, {{ $order->shipping_address['state'] ?? '' }} {{ $order->shipping_address['postal_code'] ?? '' }}<br>
                        {{ $order->shipping_address['country'] ?? '' }}
                    @else
                        -
                    @endif
                </td></tr>
            </table>

            @if ($order->status->value !== 'PAID')
                <div class="flash error" style="margin-top:14px;">当前状态不可发货（仅 PAID 可发）</div>
            @endif
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">物流信息</h1>
            <form method="POST" action="{{ url('/admin/orders/'.$order->order_no.'/ship') }}">
                @csrf
                <label style="display:block; font-size:13px; color:#374151; margin:10px 0 6px;">物流单号（留空自动生成）</label>
                <input type="text" name="tracking_no" value="{{ old('tracking_no') }}" maxlength="64" placeholder="如 CE-TRK-A1B2C3"
                       style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">

                <button type="submit" style="margin-top:18px; width:100%;"
                        {{ $order->status->value === 'PAID' ? '' : 'disabled' }}>
                    确认发货（状态 → 已发货）
                </button>
            </form>
            <small style="color:#9ca3af;">发货后订单进入 SHIPPED，物流状态 PENDING，模拟轨迹按时间推进（揽收 → 运输 → 清关 → 派送 → 签收）</small>
        </div>
    </div>
@endsection
