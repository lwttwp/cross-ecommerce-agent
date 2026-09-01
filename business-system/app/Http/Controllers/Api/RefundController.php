<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Services\OrderService;
use App\Services\RefundEventPublisher;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** 申请退款（agent 角色；创建后进入人工审批流程） */
    public function store(Request $request, string $orderNo): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return ApiResponse::fail(40401, '订单不存在', 404);
        }

        try {
            $refund = $this->orders->applyRefund($order, $data['reason'], isset($data['amount']) ? (float) $data['amount'] : null);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->businessCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::ok($this->format($refund), '退款申请已提交，等待人工审批');
    }

    /** 退款单查询 */
    public function index(Request $request): JsonResponse
    {
        $query = Refund::query()->with('order:order_no,id');
        $query->when($request->filled('refund_no'), fn ($q) => $q->where('refund_no', $request->string('refund_no')));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('order_no'), function ($q) use ($request) {
            $q->whereHas('order', fn ($o) => $o->where('order_no', $request->string('order_no')));
        });
        $query->orderByDesc('created_at');
        $pageSize = min((int) $request->input('page_size', 20), 100);
        $paginator = $query->paginate($pageSize)->withQueryString();

        return ApiResponse::ok([
            'items' => $paginator->getCollection()->map(fn (Refund $r) => $this->format($r))->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ]);
    }

    /** 人工审批：通过（admin） */
    public function approve(Request $request, int $id): JsonResponse
    {
        $refund = Refund::find($id);
        if (! $refund) {
            return ApiResponse::fail(40405, '退款单不存在', 404);
        }

        try {
            $refund = $this->orders->approveRefund($refund);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->businessCode, $e->getMessage(), $e->httpStatus);
        }

        // 方案 B: 发布审批结果事件,Agent 订阅后异步通知用户
        app(RefundEventPublisher::class)->publish([
            'refund_no' => $refund->refund_no,
            'order_no' => $refund->order?->order_no,
            'result' => 'approved',
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'approved_at' => now()->toDateTimeString(),
        ]);

        return ApiResponse::ok($this->format($refund), '退款审批通过');
    }

    /** 人工审批：驳回（admin） */
    public function reject(Request $request, int $id): JsonResponse
    {
        $refund = Refund::find($id);
        if (! $refund) {
            return ApiResponse::fail(40405, '退款单不存在', 404);
        }

        try {
            $refund = $this->orders->rejectRefund($refund);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->businessCode, $e->getMessage(), $e->httpStatus);
        }

        // 方案 B: 发布审批结果事件,Agent 订阅后异步通知用户
        app(RefundEventPublisher::class)->publish([
            'refund_no' => $refund->refund_no,
            'order_no' => $refund->order?->order_no,
            'result' => 'rejected',
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'approved_at' => now()->toDateTimeString(),
        ]);

        return ApiResponse::ok($this->format($refund), '退款申请已驳回');
    }

    private function format(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'refund_no' => $refund->refund_no,
            'order_no' => $refund->order?->order_no,
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'amount_cny' => round((float) $refund->amount * (float) ($refund->order?->exchange_rate ?? 1), 2),
            'reason' => $refund->reason,
            'status' => $refund->status->value,
            'status_label' => $refund->status->label(),
            'order_status_before' => $refund->order_status_before,
            'approved_at' => $refund->approved_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'created_at' => $refund->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
        ];
    }
}
