@extends('layouts.admin')

@section('title', '客户详情')
@section('page_sub', '客户 ' . $customer->name)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">👤 客户详情</h1>
        <a href="{{ url('/admin/customers') }}" class="reset">← 返回列表</a>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">基本信息</h1>
            <table>
                <tr><th style="width:120px;">客户ID</th><td>{{ $customer->id }}</td></tr>
                <tr><th>姓名</th><td>{{ $customer->name }}</td></tr>
                <tr><th>邮箱</th><td>{{ $customer->email }}</td></tr>
                <tr><th>电话</th><td>{{ \App\Http\Controllers\Web\CustomerController::maskPhone($customer->phone) }}</td></tr>
                <tr><th>国家</th><td>{{ $customer->country }}</td></tr>
                <tr><th>偏好币种</th><td>{{ $customer->currency }}</td></tr>
                <tr><th>注册时间</th><td>{{ $customer->created_at?->format('Y-m-d H:i') }}</td></tr>
            </table>
        </div>

        <div class="card" style="margin:0;">
            <h1 style="font-size:15px; margin-bottom:12px;">消费统计（仅已付款订单）</h1>
            <table>
                <tr><th style="width:120px;">订单总数</th><td style="font-size:20px;font-weight:700;">{{ $orderCount }}</td></tr>
                <tr><th>消费金额（CNY）</th><td style="font-size:20px;font-weight:700;">¥{{ number_format($spentCny, 2) }}</td></tr>
                <tr><th>退款相关订单</th><td>{{ $refundRelated }}</td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <h1 style="font-size:15px; margin-bottom:12px;">订单记录（{{ $orderCount }} 笔）</h1>
        <table>
            <thead><tr><th>订单号</th><th>状态</th><th>币种</th><th>实付金额</th><th>折合 CNY</th><th>下单时间</th></tr></thead>
            <tbody>
            @forelse ($customer->orders as $o)
                <tr>
                    <td><a href="{{ url('/admin/orders/'.$o->order_no) }}">{{ $o->order_no }}</a></td>
                    <td><span class="badge {{ strtolower(str_replace('_','-',$o->status->value)) }}">{{ $o->status->label() }}</span></td>
                    <td>{{ $o->currency }}</td>
                    <td>{{ number_format($o->paid_amount, 2) }}</td>
                    <td>¥{{ number_format((float) $o->paid_amount * (float) $o->exchange_rate, 2) }}</td>
                    <td class="nowrap">{{ $o->created_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">该客户暂无订单</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
