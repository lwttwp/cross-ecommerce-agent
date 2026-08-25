@extends('layouts.admin')

@section('title', '汇率配置')
@section('page_sub', '订单成交币种 → CNY 汇率快照')

@section('content')
    <h1>💱 汇率配置</h1>
    <div class="sub">下单时按此汇率折算并保存快照，历史订单不受后续调整影响</div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>币种</th><th>1 {{ '币种' }} = ? CNY</th><th>说明</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rates as $r)
                <tr>
                    <td style="font-weight:600;">{{ $r->currency }}</td>
                    <td>¥{{ number_format((float) $r->rate_to_cny, 4) }}</td>
                    <td><small>订单存汇率快照，历史数据不漂移</small></td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">暂无汇率配置</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
