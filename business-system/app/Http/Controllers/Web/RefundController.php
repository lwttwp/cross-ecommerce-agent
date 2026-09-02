<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\OrderService;
use App\Services\RefundEventPublisher;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 审批页（M2）：给人看的退款审批单页，列表 + 通过/驳回。
 */
class RefundController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** 退款审批列表（默认只看待审批，可切状态） */
    public function index(Request $request): View
    {
        $query = Refund::with('order:id,order_no,currency,exchange_rate,paid_amount')
            ->orderByDesc('created_at');

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $query->when($request->filled('keyword'), function ($q) use ($request) {
            $k = $request->string('keyword');
            $q->where(function ($qq) use ($k) {
                $qq->where('refund_no', 'ilike', "%{$k}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_no', 'ilike', "%{$k}%"));
            });
        });

        $refunds = $query->paginate(15)->withQueryString();

        return view('refunds.index', ['refunds' => $refunds]);
    }

    /** 退款详情 */
    public function show(int $id): View
    {
        $refund = Refund::with('order.customer')->findOrFail($id);

        return view('refunds.show', ['refund' => $refund]);
    }

    /** 通过 */
    public function approve(Request $request, int $id)
    {
        $refund = Refund::find($id);
        if (! $refund) {
            return back()->with('error', '退款单不存在');
        }

        try {
            $this->orders->approveRefund($refund, 'admin');
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
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

        return back()->with('success', "退款单 {$refund->refund_no} 已通过");
    }

    /** 驳回（订单恢复退款前状态） */
    public function reject(Request $request, int $id)
    {
        $refund = Refund::find($id);
        if (! $refund) {
            return back()->with('error', '退款单不存在');
        }

        try {
            $this->orders->rejectRefund($refund, 'admin');
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
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

        return back()->with('success', "退款单 {$refund->refund_no} 已驳回");
    }
}
