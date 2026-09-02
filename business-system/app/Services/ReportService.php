<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 异步报表任务执行（M2）：月度销售 / 退款率 / 订单导出。
 * 由队列 Worker 消费 RunTaskJob 后调用，结果写回 Task 的 result_summary / result_path。
 */
class ReportService
{
    /** 已付款状态（与客户消费统计口径一致：退款单金额不扣减） */
    private const PAID_STATUSES = ['PAID', 'SHIPPED', 'COMPLETED', 'REFUNDING', 'REFUNDED'];

    /** 退款相关状态 */
    private const REFUND_STATUSES = ['REFUNDING', 'REFUNDED'];

    /**
     * 写 CSV 到 storage/app/private/exports/ 并返回相对路径。
     * Worker(root) 写完后放开权限，保证 FPM(www-data) 能读（下载接口）。
     */
    private function writeCsv(string $name, array $headers, array $rows): string
    {
        $filename = 'exports/'.$name;
        $csv = fopen('php://temp', 'w');
        fputcsv($csv, $headers);
        foreach ($rows as $row) {
            fputcsv($csv, $row);
        }
        rewind($csv);
        Storage::disk('local')->makeDirectory('exports');
        Storage::disk('local')->put($filename, stream_get_contents($csv));
        fclose($csv);

        @chmod(Storage::disk('local')->path('exports'), 0755);
        @chmod(Storage::disk('local')->path($filename), 0644);

        return $filename;
    }

    /**
     * 月度销售报表：按自然月聚合销售额（折合 CNY）、订单数、退款单数。
     *
     * @param  array{date_from?: string, date_to?: string}  $params
     */
    public function monthlySales(array $params): array
    {
        $query = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->select([
                DB::raw("to_char(created_at, 'YYYY-MM') as month"),
                DB::raw('count(*) as order_count'),
                DB::raw('sum(paid_amount * exchange_rate) as sales_cny'),
                DB::raw("sum(case when status in ('REFUNDING','REFUNDED') then 1 else 0 end) as refund_count"),
            ])
            ->groupBy('month')
            ->orderBy('month');

        if (! empty($params['date_from'])) {
            $query->whereDate('created_at', '>=', $params['date_from']);
        }
        if (! empty($params['date_to'])) {
            $query->whereDate('created_at', '<=', $params['date_to']);
        }

        $rows = $query->get()->map(fn ($r) => [
            'month' => $r->month,
            'order_count' => (int) $r->order_count,
            'sales_cny' => round((float) $r->sales_cny, 2),
            'refund_count' => (int) $r->refund_count,
            'refund_rate' => $r->order_count > 0
                ? round(((int) $r->refund_count / (int) $r->order_count) * 100, 2)
                : 0.0,
        ]);

        $totalSales = $rows->sum('sales_cny');
        $totalOrders = $rows->sum('order_count');
        $totalRefunds = $rows->sum('refund_count');

        // 同时生成 CSV 产物，供下载接口使用
        $path = $this->writeCsv(
            'monthly_sales_'.now()->format('Ymd_His').'.csv',
            ['month', 'order_count', 'sales_cny', 'refund_count', 'refund_rate'],
            $rows->map(fn ($r) => array_values($r))->all(),
        );

        return [
            'summary' => [
                'range' => [
                    'date_from' => $params['date_from'] ?? null,
                    'date_to' => $params['date_to'] ?? null,
                ],
                'total_sales_cny' => round($totalSales, 2),
                'total_orders' => $totalOrders,
                'total_refunds' => $totalRefunds,
                'refund_rate' => $totalOrders > 0 ? round(($totalRefunds / $totalOrders) * 100, 2) : 0.0,
                'months' => $rows->values(),
            ],
            'path' => $path,
        ];
    }

    /**
     * 退款率报表：按月份统计退款单数 / 已付款订单数。
     *
     * @param  array{date_from?: string, date_to?: string}  $params
     */
    public function refundRate(array $params): array
    {
        $paidQuery = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->select([
                DB::raw("to_char(created_at, 'YYYY-MM') as month"),
                DB::raw('count(*) as paid_count'),
            ])
            ->groupBy('month');

        $refundQuery = Refund::query()
            ->whereIn('status', ['approved', 'pending']) // 已通过/待审批均属退款诉求
            ->select([
                DB::raw("to_char(created_at, 'YYYY-MM') as month"),
                DB::raw('count(*) as refund_count'),
            ])
            ->groupBy('month');

        if (! empty($params['date_from'])) {
            $paidQuery->whereDate('created_at', '>=', $params['date_from']);
            $refundQuery->whereDate('created_at', '>=', $params['date_from']);
        }
        if (! empty($params['date_to'])) {
            $paidQuery->whereDate('created_at', '<=', $params['date_to']);
            $refundQuery->whereDate('created_at', '<=', $params['date_to']);
        }

        $paid = $paidQuery->get()->keyBy('month');
        $refunds = $refundQuery->get()->keyBy('month');

        $months = collect($paid->keys()->merge($refunds->keys())->unique()->sort())
            ->map(fn ($month) => [
                'month' => $month,
                'paid_orders' => (int) ($paid[$month]->paid_count ?? 0),
                'refund_requests' => (int) ($refunds[$month]->refund_count ?? 0),
                'refund_rate' => ($paid[$month]->paid_count ?? 0) > 0
                    ? round((($refunds[$month]->refund_count ?? 0) / (int) $paid[$month]->paid_count) * 100, 2)
                    : 0.0,
            ])
            ->values();

        $totalPaid = $months->sum('paid_orders');
        $totalRefunds = $months->sum('refund_requests');

        // 同时生成 CSV 产物，供下载接口使用
        $path = $this->writeCsv(
            'refund_rate_'.now()->format('Ymd_His').'.csv',
            ['month', 'paid_orders', 'refund_requests', 'refund_rate'],
            $months->map(fn ($r) => array_values($r))->all(),
        );

        return [
            'summary' => [
                'range' => [
                    'date_from' => $params['date_from'] ?? null,
                    'date_to' => $params['date_to'] ?? null,
                ],
                'total_paid_orders' => $totalPaid,
                'total_refund_requests' => $totalRefunds,
                'refund_rate' => $totalPaid > 0 ? round(($totalRefunds / $totalPaid) * 100, 2) : 0.0,
                'months' => $months,
            ],
            'path' => $path,
        ];
    }

    /**
     * 订单导出：生成 CSV 到 storage/app/exports/，返回相对路径。
     *
     * @param  array{status?: string, date_from?: string, date_to?: string}  $params
     */
    public function exportOrders(array $params): array
    {
        $query = Order::query()->with('customer:id,name,email,country')
            ->select(['id', 'order_no', 'customer_id', 'status', 'currency',
                      'exchange_rate', 'total_amount', 'paid_amount', 'created_at']);
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (! empty($params['date_from'])) {
            $query->whereDate('created_at', '>=', $params['date_from']);
        }
        if (! empty($params['date_to'])) {
            $query->whereDate('created_at', '<=', $params['date_to']);
        }

        // 分批流式写 CSV: 100w 级订单不能 get() 全量进内存(会 PHP fatal exit 255)。
        // chunkById 每次 2000 条,边查边写文件句柄,内存恒定。
        $filename = 'exports/orders_'.now()->format('Ymd_His').'.csv';
        Storage::disk('local')->makeDirectory('exports');
        $handle = fopen(Storage::disk('local')->path($filename), 'w');
        fputcsv($handle, ['order_no', 'customer_name', 'customer_email', 'customer_country',
                          'status', 'currency', 'total_amount', 'paid_amount',
                          'exchange_rate', 'paid_amount_cny', 'created_at']);
        $rows = 0;
        $query->orderBy('id')->chunkById(2000, function ($orders) use ($handle, &$rows) {
            foreach ($orders as $o) {
                fputcsv($handle, [
                    $o->order_no,
                    $o->customer?->name,
                    $o->customer?->email,
                    $o->customer?->country,
                    $o->status->value,
                    $o->currency,
                    (float) $o->total_amount,
                    (float) $o->paid_amount,
                    (float) $o->exchange_rate,
                    round((float) $o->paid_amount * (float) $o->exchange_rate, 2),
                    $o->created_at?->toDateTimeString(),
                ]);
                $rows++;
            }
        });
        fclose($handle);
        @chmod(Storage::disk('local')->path('exports'), 0755);
        @chmod(Storage::disk('local')->path($filename), 0644);

        return [
            'summary' => ['filename' => basename($filename), 'rows' => $rows],
            'path' => $filename,
        ];
    }

}

