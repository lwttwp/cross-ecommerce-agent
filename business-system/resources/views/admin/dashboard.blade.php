@extends('layouts.admin')

@section('title', '仪表盘')
@section('page_sub', '各模块核心数据一览')

@section('content')
    <h1>📊 仪表盘</h1>
    <div class="sub">实时统计（订单金额按实付 × 汇率折合 CNY）</div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px;">
        <div class="card" style="margin:0;"><div style="font-size:12px;color:#6b7280;">订单总数</div><div style="font-size:24px;font-weight:700;">{{ $orderStats->total }}</div><small>近 7 日 +{{ $recentOrders }}</small></div>
        <div class="card" style="margin:0;"><div style="font-size:12px;color:#6b7280;">销售额（CNY）</div><div style="font-size:24px;font-weight:700;">¥{{ number_format((float) $orderStats->sales_cny, 0) }}</div><small>已付款订单</small></div>
        <div class="card" style="margin:0;"><div style="font-size:12px;color:#6b7280;">客户</div><div style="font-size:24px;font-weight:700;">{{ $customerCount }}</div><small>有订单 {{ $customerWithOrders }}</small></div>
        <div class="card" style="margin:0;"><div style="font-size:12px;color:#6b7280;">商品</div><div style="font-size:24px;font-weight:700;">{{ $productStats->total }}</div><small>在售 {{ $productStats->on_sale }} · 总库存 {{ $productStats->total_stock }}</small></div>
        <div class="card" style="margin:0;"><div style="font-size:12px;color:#6b7280;">退款单</div><div style="font-size:24px;font-weight:700;">{{ $refundStats->total }}</div><small>待审批 {{ $refundStats->pending }}</small></div>
        <div class="card" style="margin:0;"><div style="font-size:12px;color:#6b7280;">异步任务</div><div style="font-size:24px;font-weight:700;">{{ $taskStats->total }}</div><small>成功 {{ $taskStats->success }} · 失败 {{ $taskStats->failed }}</small></div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div class="card">
            <h1 style="font-size:15px; margin-bottom:12px;">订单状态分布</h1>
            <table>
                <thead><tr><th>状态</th><th>数量</th><th style="width:60%;">占比</th></tr></thead>
                <tbody>
                @forelse ($statusBreakdown as $row)
                    <tr>
                        <td><span class="badge {{ strtolower(str_replace('_','-',$row->status->value)) }}">{{ $statusLabels[$row->status->value] ?? $row->status->value }}</span></td>
                        <td>{{ $row->cnt }}</td>
                        <td>
                            <div style="background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;">
                                <div style="background:#2563eb;height:10px;width:{{ $orderStats->total > 0 ? round($row->cnt / $orderStats->total * 100, 1) : 0 }}%;"></div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty">暂无订单</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div>
            <div class="card">
                <h1 style="font-size:15px; margin-bottom:12px;">退款审批</h1>
                <table>
                    <thead><tr><th>状态</th><th>数量</th></tr></thead>
                    <tbody>
                    <tr><td><span class="badge pending">待审批</span></td><td>{{ $refundStats->pending }}</td></tr>
                    <tr><td><span class="badge approved">已通过</span></td><td>{{ $refundStats->approved }}</td></tr>
                    <tr><td><span class="badge rejected">已驳回</span></td><td>{{ $refundStats->rejected }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h1 style="font-size:15px; margin-bottom:12px;">异步任务</h1>
                <table>
                    <thead><tr><th>状态</th><th>数量</th></tr></thead>
                    <tbody>
                    <tr><td><span class="badge pending">排队中</span></td><td>{{ $taskStats->pending }}</td></tr>
                    <tr><td><span class="badge running">执行中</span></td><td>{{ $taskStats->running }}</td></tr>
                    <tr><td><span class="badge success">已完成</span></td><td>{{ $taskStats->success }}</td></tr>
                    <tr><td><span class="badge failed">失败</span></td><td>{{ $taskStats->failed }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
