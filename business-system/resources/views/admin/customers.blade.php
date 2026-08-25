@extends('layouts.admin')

@section('title', '客户管理')
@section('page_sub', '客户列表 + 消费统计（仅已付款订单计入金额）')

@section('content')
    <h1>👤 客户管理</h1>
    <div class="sub">共 {{ $customers->total() }} 位客户</div>

    <form class="filters" method="GET">
        <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="姓名 / 邮箱 / 国家">
        <button type="submit">搜索</button>
        <a class="reset" href="{{ url('/admin/customers') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>ID</th><th>姓名</th><th>邮箱</th><th>电话</th><th>国家</th>
                <th>币种</th><th>订单数</th><th>消费金额（CNY）</th><th>注册时间</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($customers as $c)
                <tr>
                    <td>{{ $c->id }}</td>
                    <td>{{ $c->name }}</td>
                    <td>{{ $c->email }}</td>
                    <td>{{ \App\Http\Controllers\Web\CustomerController::maskPhone($c->phone) }}</td>
                    <td>{{ $c->country }}</td>
                    <td>{{ $c->currency }}</td>
                    <td>{{ $c->order_count }}</td>
                    <td>¥{{ number_format((float) $c->sales_cny, 2) }}</td>
                    <td class="nowrap">{{ $c->created_at?->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">没有符合条件的客户</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $customers->links('pagination.custom') }}</div>
@endsection
