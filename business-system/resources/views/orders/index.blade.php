<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>订单列表 - 跨境电商智能订单助手</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f3f4f6; color: #1f2937; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #6b7280; font-size: 13px; margin-bottom: 16px; }
        form.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        select, input[type=text] {
            padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            background: #fff; font-size: 14px;
        }
        button { padding: 7px 18px; background: #2563eb; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { background: #1d4ed8; }
        .reset { align-self: center; color: #2563eb; font-size: 13px; text-decoration: none; }
        .card { background: #fff; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        table { border-collapse: collapse; width: 100%; font-size: 13.5px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 9px 8px; text-align: left; white-space: nowrap; }
        th { background: #f9fafb; color: #374151; font-weight: 600; }
        td small { color: #9ca3af; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
        .pending-payment { background: #fef3c7; color: #92400e; }
        .paid { background: #dbeafe; color: #1e40af; }
        .shipped { background: #e0e7ff; color: #3730a3; }
        .completed { background: #d1fae5; color: #065f46; }
        .cancelled { background: #f3f4f6; color: #4b5563; }
        .refunding { background: #fce7f3; color: #9d174d; }
        .refunded { background: #fee2e2; color: #991b1b; }
        .pagination { margin-top: 16px; display: flex; gap: 4px; flex-wrap: wrap; }
        .pagination a, .pagination span {
            padding: 4px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            text-decoration: none; color: #2563eb; font-size: 13px; background: #fff;
        }
        .pagination .disabled { color: #9ca3af; }
        .pagination a.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .empty { text-align: center; color: #9ca3af; padding: 40px 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>📦 订单列表</h1>
    <div class="sub">共 {{ $orders->total() }} 笔订单（近 6 个月演示数据）</div>

    <form class="filters" method="GET">
        <select name="status">
            <option value="">全部状态</option>
            @foreach (\App\Enums\OrderStatus::cases() as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="订单号 / 客户名 / 邮箱">
        <button type="submit">搜索</button>
        <a class="reset" href="{{ url('/orders') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>订单号</th>
                <th>客户</th>
                <th>状态</th>
                <th>币种</th>
                <th>实付金额</th>
                <th>折合 CNY</th>
                <th>物流</th>
                <th>下单时间</th>
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
                    <td>{{ $order->created_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">没有符合条件的订单</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $orders->links('pagination.custom') }}</div>
</div>
</body>
</html>
