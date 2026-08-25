@extends('layouts.admin')

@section('title', '订单管理')
@section('page_sub', '多条件查询 + 分页')

@section('content')
    <h1>📦 订单管理</h1>
    <div class="sub">共 {{ $orders->total() }} 笔订单（演示数据）</div>

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
                <th>实付金额</th><th>折合 CNY</th><th>物流</th><th>下单时间</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>{{ $order->order_no }}</td>
                    <td>{{ $order->customer?->name }}<br><small>{{ $order->customer?->email }}</small></td>
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
                </tr>
            @empty
                <tr><td colspan="8" class="empty">没有符合条件的订单</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $orders->links('pagination.custom') }}</div>
@endsection
