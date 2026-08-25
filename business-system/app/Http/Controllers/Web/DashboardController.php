<?php

namespace App\Http\Controllers\Web;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 后台仪表盘：各模块核心数据一览。
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $paidStatuses = ['PAID', 'SHIPPED', 'COMPLETED', 'REFUNDING', 'REFUNDED'];

        // 订单
        $orderStats = Order::query()
            ->selectRaw("count(*) as total,
                sum(case when status in ('PAID','SHIPPED','COMPLETED','REFUNDING','REFUNDED') then 1 else 0 end) as paid,
                sum(case when status = 'PENDING_PAYMENT' then 1 else 0 end) as pending_payment,
                sum(case when status = 'CANCELLED' then 1 else 0 end) as cancelled,
                sum(case when status in ('PAID','SHIPPED','COMPLETED','REFUNDING','REFUNDED') then paid_amount * exchange_rate else 0 end) as sales_cny")
            ->first();

        // 按状态分布
        $statusBreakdown = Order::query()
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->orderByDesc('cnt')
            ->get();

        // 退款
        $refundStats = Refund::query()
            ->selectRaw("count(*) as total,
                sum(case when status = 'pending' then 1 else 0 end) as pending,
                sum(case when status = 'approved' then 1 else 0 end) as approved,
                sum(case when status = 'rejected' then 1 else 0 end) as rejected")
            ->first();

        // 任务
        $taskStats = Task::query()
            ->selectRaw("count(*) as total,
                sum(case when status = 'pending' then 1 else 0 end) as pending,
                sum(case when status = 'running' then 1 else 0 end) as running,
                sum(case when status = 'success' then 1 else 0 end) as success,
                sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->first();

        // 商品
        $productStats = Product::query()
            ->selectRaw('count(*) as total,
                sum(case when status = \'on\' then 1 else 0 end) as on_sale,
                sum(stock) as total_stock')
            ->first();

        // 近 7 日订单趋势
        $recentOrders = Order::query()
            ->whereDate('created_at', '>=', now()->subDays(7))
            ->count();

        return view('admin.dashboard', [
            'orderStats' => $orderStats,
            'statusBreakdown' => $statusBreakdown,
            'refundStats' => $refundStats,
            'taskStats' => $taskStats,
            'productStats' => $productStats,
            'customerCount' => Customer::count(),
            'customerWithOrders' => Customer::whereHas('orders')->count(),
            'rateCount' => ExchangeRate::count(),
            'recentOrders' => $recentOrders,
            'statusLabels' => collect(OrderStatus::cases())->keyBy(fn ($s) => $s->value)->map(fn ($s) => $s->label()),
        ]);
    }
}
