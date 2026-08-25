@extends('layouts.admin')

@section('title', '商品管理')
@section('page_sub', '商品与库存一览')

@section('content')
    <h1>🏷️ 商品管理</h1>
    <div class="sub">共 {{ $products->total() }} 个商品</div>

    <form class="filters" method="GET">
        <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="SKU / 名称 / 分类">
        <select name="status">
            <option value="">全部状态</option>
            <option value="on" @selected(request('status') === 'on')>在售</option>
            <option value="off" @selected(request('status') === 'off')>下架</option>
        </select>
        <input type="number" name="low_stock" value="{{ request('low_stock') }}" placeholder="低库存阈值" style="width:110px;">
        <button type="submit">筛选</button>
        <a class="reset" href="{{ url('/admin/products') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>SKU</th><th>名称</th><th>分类</th><th>单价（USD）</th>
                <th>库存</th><th>重量(kg)</th><th>状态</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($products as $p)
                <tr>
                    <td><a href="{{ url('/admin/products/'.$p->sku) }}">{{ $p->sku }}</a></td>
                    <td><a href="{{ url('/admin/products/'.$p->sku) }}">{{ $p->name }}</a></td>
                    <td>{{ $p->category }}</td>
                    <td>{{ number_format($p->price, 2) }}</td>
                    <td>
                        @if ($p->stock <= 5)
                            <span style="color:#dc2626;font-weight:600;">{{ $p->stock }}</span>
                        @else
                            {{ $p->stock }}
                        @endif
                    </td>
                    <td>{{ $p->weight_kg }}</td>
                    <td>
                        <span class="badge {{ $p->status === 'on' ? 'approved' : 'cancelled' }}">
                            {{ $p->status === 'on' ? '在售' : '下架' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">没有符合条件的商品</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $products->links('pagination.custom') }}</div>
@endsection
