<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 后台客户管理：列表 + 消费统计（口径与 API 一致：仅已付款状态计入金额）。
 */
class CustomerController extends Controller
{
    private const PAID_STATUSES = ['PAID', 'SHIPPED', 'COMPLETED', 'REFUNDING', 'REFUNDED'];

    public function index(Request $request): View
    {
        $query = Customer::query()
            ->leftJoin('orders', 'orders.customer_id', '=', 'customers.id')
            ->selectRaw('customers.*,
                count(orders.id) as order_count,
                sum(case when orders.status in (\'PAID\',\'SHIPPED\',\'COMPLETED\',\'REFUNDING\',\'REFUNDED\')
                    then orders.paid_amount * orders.exchange_rate else 0 end) as sales_cny')
            ->groupBy('customers.id');

        $query->when($request->filled('keyword'), function ($q) use ($request) {
            $k = $request->string('keyword');
            $q->where(function ($qq) use ($k) {
                $qq->where('customers.name', 'ilike', "%{$k}%")
                    ->orWhere('customers.email', 'ilike', "%{$k}%")
                    ->orWhere('customers.country', 'ilike', "%{$k}%");
            });
        });

        $query->orderByDesc('customers.created_at');

        $customers = $query->paginate(15)->withQueryString();

        return view('admin.customers', ['customers' => $customers]);
    }

    /** 手机号中间四位打码 */
    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }
}
