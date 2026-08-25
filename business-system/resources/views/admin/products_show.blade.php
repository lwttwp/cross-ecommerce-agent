@extends('layouts.admin')

@section('title', '商品详情')
@section('page_sub', 'SKU ' . $product->sku)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">🏷️ 商品详情</h1>
        <a href="{{ url('/admin/products') }}" class="reset">← 返回列表</a>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">基本信息</h1>
            <table>
                <tr><th style="width:120px;">SKU</th><td>{{ $product->sku }}</td></tr>
                <tr><th>名称</th><td>{{ $product->name }}</td></tr>
                <tr><th>分类</th><td>{{ $product->category }}</td></tr>
                <tr><th>基准价</th><td>{{ number_format($product->price, 2) }} USD</td></tr>
                <tr><th>重量</th><td>{{ $product->weight_kg }} kg</td></tr>
                <tr><th>状态</th><td>
                    <span class="badge {{ $product->status === 'on' ? 'approved' : 'cancelled' }}">
                        {{ $product->status === 'on' ? '在售' : '下架' }}
                    </span>
                </td></tr>
            </table>
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">销售统计（已付款订单）</h1>
            <table>
                <tr>
                    <th style="width:120px;">当前库存</th>
                    <td style="font-size:20px;font-weight:700; {{ $product->stock <= 5 ? 'color:#dc2626;' : '' }}">{{ $product->stock }}</td>
                </tr>
                <tr><th>累计销量</th><td style="font-size:20px;font-weight:700;">{{ $sold }} 件</td></tr>
                <tr><th>累计营收（CNY）</th><td style="font-size:20px;font-weight:700;">¥{{ number_format($revenueCny, 2) }}</td></tr>
            </table>
        </div>
    </div>

    @if ($product->description)
        <div class="card" style="margin-bottom:16px;">
            <h1 style="font-size:15px; margin-bottom:8px;">描述</h1>
            <div style="font-size:13px; color:#4b5563; white-space:pre-wrap;">{{ $product->description }}</div>
        </div>
    @endif

    <div class="card">
        <h1 style="font-size:15px; margin-bottom:12px;">相关订单</h1>
        <table>
            <thead><tr><th>订单号</th><th>客户</th><th>状态</th><th>数量</th><th>下单时间</th></tr></thead>
            <tbody>
            @forelse ($orders as $o)
                <tr>
                    <td><a href="{{ url('/admin/orders/'.$o->order_no) }}">{{ $o->order_no }}</a></td>
                    <td>{{ $o->customer?->name }}<br><small>{{ $o->customer?->email }}</small></td>
                    <td><span class="badge {{ strtolower(str_replace('_','-',$o->status->value)) }}">{{ $o->status->label() }}</span></td>
                    <td>
                        @php $item = $o->items->firstWhere('sku', $product->sku); @endphp
                        {{ $item?->quantity ?? '-' }}
                    </td>
                    <td class="nowrap">{{ $o->created_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">暂无相关订单</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="pagination">{{ $orders->links('pagination.custom') }}</div>
    </div>
@endsection
