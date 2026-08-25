@extends('layouts.admin')

@section('title', '订单详情')
@section('page_sub', '订单 ' . $order->order_no)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">📦 订单详情</h1>
        <a href="{{ url('/admin/orders') }}" class="reset">← 返回列表</a>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">订单信息</h1>
            <table>
                <tr><th style="width:120px;">订单号</th><td>{{ $order->order_no }}</td></tr>
                <tr><th>状态</th><td><span class="badge {{ strtolower(str_replace('_','-',$order->status->value)) }}">{{ $order->status->label() }}</span></td></tr>
                <tr><th>币种</th><td>{{ $order->currency }}</td></tr>
                <tr><th>订单总额</th><td>{{ number_format($order->total_amount, 2) }}</td></tr>
                <tr><th>实付金额</th><td>{{ number_format($order->paid_amount, 2) }} {{ $order->currency }}</td></tr>
                <tr><th>汇率快照</th><td>1 {{ $order->currency }} = ¥{{ $order->exchange_rate }}</td></tr>
                <tr><th>折合 CNY</th><td style="font-weight:600;">¥{{ number_format((float) $order->paid_amount * (float) $order->exchange_rate, 2) }}</td></tr>
                <tr><th>下单时间</th><td>{{ $order->created_at?->format('Y-m-d H:i:s') }}</td></tr>
            </table>
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">客户信息</h1>
            @if ($order->customer)
                <table>
                    <tr><th style="width:120px;">客户ID</th><td>{{ $order->customer->id }}</td></tr>
                    <tr><th>姓名</th><td><a href="{{ url('/admin/customers/'.$order->customer->id) }}">{{ $order->customer->name }}</a></td></tr>
                    <tr><th>邮箱</th><td>{{ $order->customer->email }}</td></tr>
                    <tr><th>国家</th><td>{{ $order->customer->country }}</td></tr>
                    <tr><th>电话</th><td>{{ \App\Http\Controllers\Web\CustomerController::maskPhone($order->customer->phone) }}</td></tr>
                </table>
            @else
                <div class="empty">客户信息缺失</div>
            @endif

            <h1 style="font-size:15px; margin:20px 0 12px;">物流信息</h1>
            <table>
                <tr><th style="width:120px;">物流单号</th><td>{{ $order->tracking_no ?? '-' }}</td></tr>
                <tr><th>物流状态</th><td>{{ $order->logistics_status?->label() ?? '-' }}</td></tr>
                <tr><th>支付时间</th><td>{{ $order->paid_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                <tr><th>发货时间</th><td>{{ $order->shipped_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                <tr><th>完成时间</th><td>{{ $order->completed_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
            </table>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">收货地址</h1>
            @if ($order->shipping_address)
                <table>
                    <tr><th style="width:120px;">收件人</th><td>{{ $order->shipping_address['recipient_name'] ?? '-' }}</td></tr>
                    <tr><th>电话</th><td>{{ $order->shipping_address['phone'] ?? '-' }}</td></tr>
                    <tr><th>国家</th><td>{{ $order->shipping_address['country'] ?? '-' }}</td></tr>
                    <tr><th>州/省</th><td>{{ $order->shipping_address['state'] ?? '-' }}</td></tr>
                    <tr><th>城市</th><td>{{ $order->shipping_address['city'] ?? '-' }}</td></tr>
                    <tr><th>地址</th><td>{{ $order->shipping_address['address_line1'] ?? '-' }}</td></tr>
                    <tr><th>邮编</th><td>{{ $order->shipping_address['postal_code'] ?? '-' }}</td></tr>
                </table>
            @else
                <div class="empty">无收货地址</div>
            @endif
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">状态时间线</h1>
            @forelse ($order->statusLogs as $log)
                <div style="display:flex; gap:10px; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:13px;">
                    <span class="badge {{ strtolower(str_replace('_','-',$log->to_status ?? 'pending')) }}">{{ $log->to_status ?? '-' }}</span>
                    <span style="flex:1;">{{ $log->remark }}</span>
                    <small style="color:#9ca3af;">{{ $log->created_at?->format('m-d H:i') }} · {{ $log->operator }}</small>
                </div>
            @empty
                <div class="empty">无状态记录</div>
            @endforelse
        </div>
    </div>

    <div class="card">
        <h1 style="font-size:15px; margin-bottom:12px;">商品明细</h1>
        <table>
            <thead><tr><th>SKU</th><th>名称</th><th>数量</th><th>单价</th><th>小计</th></tr></thead>
            <tbody>
            @forelse ($order->items as $item)
                <tr>
                    <td><a href="{{ url('/admin/products/'.$item->sku) }}">{{ $item->sku }}</a></td>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format($item->unit_price, 2) }}</td>
                    <td>{{ number_format($item->unit_price * $item->quantity, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">无商品明细</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
