<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web 页面控制器（给人看的网页，区别于 Api 命名空间）
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** 订单列表：支持状态筛选 + 关键词搜索 + 分页 */
    public function index(Request $request): View
    {
        $query = Order::with('customer:id,name,email,country');

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $query->when($request->filled('keyword'), function ($q) use ($request) {
            $keyword = trim($request->string('keyword'));
            // 订单号样式(CE 开头): 右模糊走 text_pattern_ops 索引; 否则客户名/邮箱包含模糊(trgm)
            if (preg_match('/^ce\d+/i', $keyword)) {
                $q->where('order_no', 'like', strtoupper($keyword).'%');
            } else {
                $q->where(function ($qq) use ($keyword) {
                    $qq->where('order_no', 'ilike', "%{$keyword}%")
                        ->orWhereHas('customer', fn ($c) => $c
                            ->where('name', 'ilike', "%{$keyword}%")
                            ->orWhere('email', 'ilike', "%{$keyword}%"));
                });
            }
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

    /** 退款申请表单页（仅 PAID/SHIPPED/COMPLETED 可退） */
    public function refundForm(string $orderNo): View
    {
        $order = Order::with(['customer:id,name,email,country', 'items'])
            ->where('order_no', $orderNo)
            ->firstOrFail();

        return view('orders.refund', ['order' => $order]);
    }

    /** 提交退款申请（走 OrderService 校验与状态流转） */
    public function refund(Request $request, string $orderNo): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return back()->with('error', '订单不存在');
        }

        try {
            $refund = $this->orders->applyRefund(
                $order,
                $data['reason'],
                isset($data['amount']) ? (float) $data['amount'] : null,
                'admin',
            );
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/admin/refunds')
            ->with('success', "退款申请 {$refund->refund_no} 已提交，等待审批");
    }

    /** 下单表单页 */
    public function createForm(): View
    {
        return view('orders.create', [
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'email', 'country']),
            'products' => Product::where('status', 'on')->orderBy('sku')->get(['sku', 'name', 'price', 'stock']),
            'currencies' => ExchangeRate::orderBy('currency')->pluck('currency'),
        ]);
    }

    /** 下单（事务扣库存，走 OrderService） */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'currency' => ['required', 'string', 'max:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'recipient_name' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'country' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'city' => ['required', 'string'],
            'address_line1' => ['required', 'string'],
            'postal_code' => ['required', 'string'],
        ]);

        try {
            $order = $this->orders->create([
                'customer_id' => (int) $data['customer_id'],
                'currency' => $data['currency'],
                'items' => array_values($data['items']),
                'shipping_address' => [
                    'recipient_name' => $data['recipient_name'],
                    'phone' => $data['phone'],
                    'country' => $data['country'],
                    'state' => $data['state'] ?? null,
                    'city' => $data['city'],
                    'address_line1' => $data['address_line1'],
                    'postal_code' => $data['postal_code'],
                ],
            ]);
        } catch (BusinessException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/admin/orders/'.$order->order_no)
            ->with('success', "订单 {$order->order_no} 创建成功（待支付）");
    }

    /** 标记支付（PENDING_PAYMENT → PAID） */
    public function pay(Request $request, string $orderNo): RedirectResponse
    {
        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return back()->with('error', '订单不存在');
        }

        try {
            $this->orders->pay($order, 'admin');
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "订单 {$order->order_no} 已支付");
    }

    /** 发货表单页（仅 PAID 可发） */
    public function shipForm(string $orderNo): View
    {
        $order = Order::with('customer:id,name,email,country')
            ->where('order_no', $orderNo)
            ->firstOrFail();

        return view('orders.ship', ['order' => $order]);
    }

    /** 发货（PAID → SHIPPED，写入物流单号） */
    public function ship(Request $request, string $orderNo): RedirectResponse
    {
        $data = $request->validate([
            'tracking_no' => ['nullable', 'string', 'max:64'],
        ]);

        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return back()->with('error', '订单不存在');
        }

        $trackingNo = $data['tracking_no'] !== null && trim($data['tracking_no']) !== ''
            ? trim($data['tracking_no'])
            : 'CE-TRK-'.strtoupper(bin2hex(random_bytes(3)));

        try {
            $this->orders->ship($order, $trackingNo, 'admin');
        } catch (BusinessException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/admin/orders')
            ->with('success', "订单 {$order->order_no} 已发货（运单号 {$trackingNo}）");
    }

    /** 标记签收（SHIPPED → COMPLETED） */
    public function complete(Request $request, string $orderNo): RedirectResponse
    {
        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return back()->with('error', '订单不存在');
        }

        try {
            $this->orders->markCompleted($order, 'admin');
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "订单 {$order->order_no} 已签收完成");
    }
}
