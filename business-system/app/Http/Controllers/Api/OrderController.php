<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Carbon;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** 多条件查询订单（订单号/客户/状态/币种/日期/关键词） */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->with('customer:id,name,email,country');
        $query->when($request->filled('order_no'), fn ($q) => $q->where('order_no', 'like', strtoupper($request->string('order_no')).'%'));
        $query->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', (int) $request->input('customer_id')));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('currency'), fn ($q) => $q->where('currency', strtoupper($request->string('currency'))));

        $query->when($request->filled('date_from'), function ($q) use ($request) {
            $q->where('created_at', '>=', Carbon::parse($request->string('date_from'))->startOfDay());
        });
        $query->when($request->filled('date_to'), function ($q) use ($request) {
            $q->where('created_at', '<=', Carbon::parse($request->string('date_to'))->endOfDay());
        });
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

        $pageSize = min((int) $request->input('page_size', 20), 100);
        $paginator = $query->paginate($pageSize)->withQueryString();


        return ApiResponse::ok([
            'items' => $paginator->getCollection()->map(fn (Order $o) => $this->format($o))->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ]);
    }

    /** 订单详情（含商品明细、时间线、折合 CNY） */
    public function show(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::with(['items', 'customer:id,name,email,country', 'statusLogs'])
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            return ApiResponse::fail(40401, '订单不存在', 404);
        }

        return ApiResponse::ok($this->format($order));
    }

    /** 下单（事务扣库存） */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'currency' => ['required', 'string', 'max:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.recipient_name' => ['required', 'string'],
            'shipping_address.phone' => ['required', 'string'],
            'shipping_address.country' => ['required', 'string'],
            'shipping_address.state' => ['nullable', 'string'],
            'shipping_address.city' => ['required', 'string'],
            'shipping_address.address_line1' => ['required', 'string'],
            'shipping_address.postal_code' => ['required', 'string'],
        ]);

        try {
            $order = $this->orders->create($data);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->businessCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::ok($this->format($order->load(['items', 'customer', 'statusLogs'])), '下单成功');
    }

    /** 修改收货地址（仅未发货） */
    public function updateAddress(Request $request, string $orderNo): JsonResponse
    {
        $address = $request->validate([
            'recipient_name' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'country' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'city' => ['required', 'string'],
            'address_line1' => ['required', 'string'],
            'postal_code' => ['required', 'string'],
        ]);

        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return ApiResponse::fail(40401, '订单不存在', 404);
        }

        try {
            $order = $this->orders->updateAddress($order, $address, (string) $request->attributes->get('api_role'));
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->businessCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::ok($this->format($order->load('customer')), '收货地址已更新');
    }

    /** 取消订单（仅待支付） */
    public function cancel(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return ApiResponse::fail(40401, '订单不存在', 404);
        }

        try {
            $order = $this->orders->cancel($order, (string) $request->attributes->get('api_role'));
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->businessCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::ok($this->format($order->load('customer')), '订单已取消');
    }

    /** 物流轨迹 */
    public function tracking(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return ApiResponse::fail(40401, '订单不存在', 404);
        }
        if (! $order->tracking_no) {
            return ApiResponse::fail(40402, '订单未发货，暂无物流信息', 404);
        }

        return ApiResponse::ok([
            'order_no' => $order->order_no,
            'tracking_no' => $order->tracking_no,
            'logistics_status' => $order->logistics_status?->value,
            'logistics_label' => $order->logistics_status?->label(),
            'timeline' => $this->orders->trackingTimeline($order),
        ]);
    }

    private function format(Order $order): array
    {
        $paidCny = round((float) $order->paid_amount * (float) $order->exchange_rate, 2);

        return [
            'order_no' => $order->order_no,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'currency' => $order->currency,
            'exchange_rate' => (float) $order->exchange_rate,
            'total_amount' => (float) $order->total_amount,
            'paid_amount' => (float) $order->paid_amount,
            'paid_amount_cny' => $paidCny,
            'customer' => $order->customer
                ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'email' => $order->customer->email,
                    'country' => $order->customer->country,
                ]
                : null,
            'items' => $order->items->map(fn ($i) => [
                'sku' => $i->sku,
                'name' => $i->name,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
            ])->values(),
            'shipping_address' => $order->shipping_address,
            'tracking_no' => $order->tracking_no,
            'logistics_status' => $order->logistics_status?->value,
            'logistics_label' => $order->logistics_status?->label(),
            'timeline' => $order->statusLogs->map(fn ($l) => [
                'from' => $l->from_status,
                'to' => $l->to_status,
                'remark' => $l->remark,
                'operator' => $l->operator,
                'at' => $l->created_at?->toIso8601String(),
            ])->values(),
            'created_at' => $order->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
        ];
    }
}
