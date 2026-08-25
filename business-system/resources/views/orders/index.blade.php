@extends('layouts.admin')

@section('title', '订单管理')
@section('page_sub', '多条件查询 + 分页')

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
            <h1 style="margin:0;">📦 订单管理</h1>
            <div class="sub" style="margin:4px 0 0;">共 {{ $orders->total() }} 笔订单（演示数据）</div>
        </div>
        <a href="{{ url('/admin/orders/create') }}" style="background:#059669; color:#fff; padding:9px 18px; border-radius:8px; text-decoration:none; font-size:14px;">＋ 下单</a>
    </div>

    <form class="filters" method="GET">
        <select name="status">
            <option value="">全部状态</option>
            @foreach (\App\Enums\OrderStatus::cases() as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="订单号 / 客户名 / 邮箱">
        <button type="submit">搜索</button>
        <a class="reset" href="{{ url('/admin/orders') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>订单号</th><th>客户</th><th>状态</th><th>币种</th>
                <th>实付金额</th><th>折合 CNY</th><th>物流</th><th>下单时间</th><th>操作</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td><a href="{{ url('/admin/orders/'.$order->order_no) }}">{{ $order->order_no }}</a></td>
                    <td>
                        @if ($order->customer)
                            <a href="{{ url('/admin/customers/'.$order->customer->id) }}">{{ $order->customer->name }}</a><br><small>{{ $order->customer->email }}</small>
                        @else
                            <small>-</small>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ strtolower(str_replace('_', '-', $order->status->value)) }}">
                            {{ $order->status->label() }}
                        </span>
                    </td>
                    <td>{{ $order->currency }}</td>
                    <td>{{ number_format($order->paid_amount, 2) }}</td>
                    <td>¥{{ number_format((float) $order->paid_amount * (float) $order->exchange_rate, 2) }}</td>
                    <td>{{ $order->logistics_status?->label() ?? '-' }}</td>
                    <td class="nowrap">{{ $order->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="nowrap">
                        @php $st = $order->status->value; @endphp
                        @if ($st === 'PAID')
                            <a href="{{ url('/admin/orders/'.$order->order_no.'/ship') }}" style="color:#1d4ed8; font-size:13px; text-decoration:none;">发货</a>
                        @elseif ($st === 'SHIPPED')
                            <form method="POST" action="{{ url('/admin/orders/'.$order->order_no.'/complete') }}" style="display:inline;">
                                @csrf
                                <button type="submit" style="background:#059669; padding:4px 12px; font-size:13px;">签收</button>
                            </form>
                        @endif
                        @if (in_array($st, ['PAID', 'SHIPPED', 'COMPLETED'], true))
                            <a href="{{ url('/admin/orders/'.$order->order_no.'/refund') }}" style="color:#dc2626; font-size:13px; text-decoration:none; margin-left:8px;">退款</a>
                        @endif
                        @if (! in_array($st, ['PAID', 'SHIPPED', 'COMPLETED'], true))
                            <small>-</small>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">没有符合条件的订单</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $orders->links('pagination.custom') }}</div>
@endsection
