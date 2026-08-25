<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>退款审批 - 跨境电商智能订单助手</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f3f4f6; color: #1f2937; }
        .wrap { max-width: 1000px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #6b7280; font-size: 13px; margin-bottom: 16px; }
        form.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        select {
            padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            background: #fff; font-size: 14px;
        }
        button { padding: 7px 18px; background: #2563eb; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { background: #1d4ed8; }
        .reset { align-self: center; color: #2563eb; font-size: 13px; text-decoration: none; }
        .card { background: #fff; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        table { border-collapse: collapse; width: 100%; font-size: 13.5px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 9px 8px; text-align: left; }
        th { background: #f9fafb; color: #374151; font-weight: 600; }
        td small { color: #9ca3af; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
        .pending { background: #fef3c7; color: #92400e; }
        .approved { background: #d1fae5; color: #065f46; }
        .rejected { background: #fee2e2; color: #991b1b; }
        .btn-approve { background: #059669; }
        .btn-approve:hover { background: #047857; }
        .btn-reject { background: #dc2626; }
        .btn-reject:hover { background: #b91c1c; }
        .btn-xs { padding: 5px 14px; font-size: 13px; border-radius: 6px; }
        .actions { display: flex; gap: 6px; }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 14px; }
        .flash.success { background: #d1fae5; color: #065f46; }
        .flash.error { background: #fee2e2; color: #991b1b; }
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
    <h1>💸 退款审批</h1>
    <div class="sub">共 {{ $refunds->total() }} 笔退款申请（human-in-the-loop：通过/驳回均写审计日志）</div>

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
        <a class="reset" href="{{ url('/refunds') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>退款单号</th>
                <th>订单号</th>
                <th>金额</th>
                <th>折合 CNY</th>
                <th>原因</th>
                <th>状态</th>
                <th>申请时间</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($refunds as $refund)
                <tr>
                    <td>{{ $refund->refund_no }}</td>
                    <td>{{ $refund->order?->order_no }}</td>
                    <td>{{ number_format($refund->amount, 2) }} {{ $refund->currency }}</td>
                    <td>¥{{ number_format((float) $refund->amount * (float) ($refund->order?->exchange_rate ?? 1), 2) }}</td>
                    <td style="max-width: 220px; white-space: normal;">{{ $refund->reason }}</td>
                    <td><span class="badge {{ $refund->status->value }}">{{ $refund->status->label() }}</span></td>
                    <td>{{ $refund->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        @if ($refund->status->value === 'pending')
                            <div class="actions">
                                <form method="POST" action="{{ url("/refunds/{$refund->id}/approve") }}">
                                    @csrf
                                    <button class="btn-approve btn-xs" type="submit">通过</button>
                                </form>
                                <form method="POST" action="{{ url("/refunds/{$refund->id}/reject") }}">
                                    @csrf
                                    <button class="btn-reject btn-xs" type="submit">驳回</button>
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
</div>
</body>
</html>
