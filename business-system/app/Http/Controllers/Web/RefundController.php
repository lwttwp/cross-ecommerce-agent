<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\OrderService;
use Illuminate\Http\Request;

/**
 * 审批页（M2）：给人看的退款审批单页，列表 + 通过/驳回。
 */
class RefundController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** 退款审批列表（默认只看待审批，可切状态） */
    public function index(Request $request)
    {
        $query = Refund::with('order:id,order_no,currency,exchange_rate,paid_amount')
            ->orderByDesc('created_at');

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $refunds = $query->paginate(15)->withQueryString();

        return view('refunds.index', ['refunds' => $refunds]);
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

        return back()->with('success', "退款单 {$refund->refund_no} 已驳回");
    }
}
