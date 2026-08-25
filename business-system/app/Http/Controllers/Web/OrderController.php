<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web 页面控制器（给人看的网页，区别于 Api 命名空间）
 */
class OrderController extends Controller
{
    /** 订单列表：支持状态筛选 + 关键词搜索 + 分页 */
    public function index(Request $request): View
    {
        $query = Order::with('customer:id,name,email,country');

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $query->when($request->filled('keyword'), function ($q) use ($request) {
            $keyword = $request->string('keyword');
            $q->where(function ($qq) use ($keyword) {
                $qq->where('order_no', 'ilike', "%{$keyword}%")
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'ilike', "%{$keyword}%")
                        ->orWhere('email', 'ilike', "%{$keyword}%"));
            });
        });

        $query->orderByDesc('created_at');

        $orders = $query->paginate(15)->withQueryString();

        return view('orders.index', ['orders' => $orders]);
    }

    /** 订单详情：商品明细 + 时间线 + 收货地址 + 物流 */
    public function show(string $orderNo): View
    {
        $order = Order::with(['items', 'customer', 'statusLogs'])
            ->where('order_no', $orderNo)
            ->firstOrFail();

        return view('orders.show', ['order' => $order]);
    }
}
