<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $query = Product::query();

        $query->when($request->filled('keyword'), function ($q) use ($request) {
            $k = $request->string('keyword');
            $q->where(function ($qq) use ($k) {
                $qq->where('sku', 'ilike', "%{$k}%")
                    ->orWhere('name', 'ilike', "%{$k}%")
                    ->orWhere('category', 'ilike', "%{$k}%");
            });
        });
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('low_stock'), fn ($q) => $q->where('stock', '<=', (int) $request->input('low_stock')));

        $query->orderByDesc('created_at');

        $products = $query->paginate(15)->withQueryString();

        return view('admin.products', ['products' => $products]);
    }

    /** 商品详情：基本信息 + 销量统计 + 相关订单 */
    public function show(string $sku): View
    {
        $product = Product::where('sku', $sku)->firstOrFail();

        // 销量统计：已付款订单的商品数量
        $sold = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.sku', $sku)
            ->whereIn('orders.status', ['PAID', 'SHIPPED', 'COMPLETED', 'REFUNDING', 'REFUNDED'])
            ->sum('order_items.quantity');

        $revenueCny = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.sku', $sku)
            ->whereIn('orders.status', ['PAID', 'SHIPPED', 'COMPLETED', 'REFUNDING', 'REFUNDED'])
            ->sum(\Illuminate\Support\Facades\DB::raw('order_items.quantity * order_items.unit_price * orders.exchange_rate'));

        // 相关订单（含未支付，全部列出）
        $orders = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->with(['customer:id,name,email', 'items'])
            ->where('order_items.sku', $sku)
            ->select('orders.*')
            ->orderByDesc('orders.created_at')
            ->paginate(10);

        return view('admin.products_show', [
            'product' => $product,
            'sold' => (int) $sold,
            'revenueCny' => round((float) $revenueCny, 2),
            'orders' => $orders,
        ]);
    }
}
