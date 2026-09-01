<?php

namespace App\Console\Commands;

use App\Enums\LogisticsStatus;
use App\Enums\TaskStatus;
use App\Models\ExchangeRate;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 生成百万级闭环演示数据：customers + orders + order_items + order_status_logs + refunds + tasks。
 * 全部走批量多行 INSERT（不走 Eloquent），事务分批提交。
 *
 * 用法：php artisan data:mega --orders=1000000 --customers=150000 --tasks=20000
 */
class GenerateMegaData extends Command
{
    protected $signature = 'data:mega
        {--orders=1000000 : 订单数}
        {--customers=150000 : 客户数}
        {--tasks=20000 : 任务数}
        {--no-truncate : 不先清空业务表}';

    protected $description = '生成百万级闭环演示数据（订单/明细/状态日志/退款/任务）';

    private const BATCH = 2000;

    /** 状态分布（百分比） */
    private const STATUS_WEIGHTS = [
        'COMPLETED' => 70,
        'SHIPPED' => 8,
        'PAID' => 6,
        'PENDING_PAYMENT' => 5,
        'CANCELLED' => 7,
        'REFUNDED' => 3,
        'REFUNDING' => 1,
    ];

    private const CURRENCY_WEIGHTS = ['USD' => 60, 'EUR' => 15, 'GBP' => 10, 'JPY' => 8, 'SGD' => 7];

    private const COUNTRIES_WEIGHTS = ['US' => 30, 'CA' => 12, 'UK' => 12, 'DE' => 10, 'FR' => 8, 'AU' => 8, 'JP' => 8, 'SG' => 5, 'ES' => 4, 'IT' => 3];

    private const REFUND_REASONS = [
        '尺寸不合适', '商品与描述不符', '不想要了', '质量问题', '发错货', '物流太慢申请退款',
        '重复下单', '颜色不对', '收到破损', '规格选错',
    ];

    private const FIRST_NAMES = ['James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda', 'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Charles', 'Karen', 'Li', 'Wei', 'Fang', 'Ming', 'Xiu', 'Yan', 'Hiroshi', 'Yuki', 'Takeshi', 'Sakura', 'Hans', 'Anna', 'Lukas', 'Elena', 'Marco', 'Giulia'];
    private const LAST_NAMES = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Wang', 'Li', 'Zhang', 'Chen', 'Liu', 'Tanaka', 'Sato', 'Suzuki', 'Takahashi', 'Watanabe', 'Muller', 'Schmidt', 'Weber', 'Wagner', 'Becker', 'Rossi', 'Russo', 'Ferrari', 'Bianchi', 'Romano', 'Wilson', 'Moore', 'Taylor', 'Anderson', 'Thomas', 'Jackson'];
    private const CITIES = ['Los Angeles', 'New York', 'Toronto', 'London', 'Berlin', 'Paris', 'Tokyo', 'Singapore', 'Sydney', 'Madrid', 'Milan'];
    private const ADDRESSES = ['1234 Maple St', '88 King Rd', '5th Ave 210', '7 Harbour Blvd', '22 Sunset Dr', '15 Market Sq', '9 Cherry Ln', '300 Lakeside Ave'];
    private const PHONE_PREFIX = ['US' => '+1', 'CA' => '+1', 'UK' => '+44', 'DE' => '+49', 'FR' => '+33', 'AU' => '+61', 'JP' => '+81', 'SG' => '+65', 'ES' => '+34', 'IT' => '+39'];
    private const STATE_POOL = ['CA', 'NY', 'TX', 'FL', 'WA', 'ON', 'BC', 'ENG', 'BAV', 'IDF', 'HOK', 'KTN', ''];

    private array $currencies = [];
    private array $products = [];
    private int $customerCount = 0;
    private int $orderSeq = 0;
    private int $refundSeq = 0;

    public function handle(): int
    {
        $orders = (int) $this->option('orders');
        $customers = (int) $this->option('customers');
        $tasks = (int) $this->option('tasks');
        $this->customerCount = $customers;

        if ($orders > 2_000_000 || $customers > 500_000 || $tasks > 100_000) {
            $this->error('规模超限：orders≤200万 / customers≤50万 / tasks≤10万');

            return 1;
        }

        $t0 = microtime(true);

        if (! $this->option('no-truncate')) {
            $this->warn('清空业务表...');
            DB::statement('TRUNCATE TABLE order_status_logs, order_items, refunds, tasks, orders, customers RESTART IDENTITY CASCADE');
        }

        $this->currencies = ExchangeRate::pluck('rate_to_cny', 'currency')->all();
        $this->products = Product::orderBy('id')->get(['id', 'sku', 'name', 'category', 'price', 'currency', 'weight_kg'])->all();

        $this->info('汇率: '.json_encode($this->currencies));
        $this->info('商品数: '.count($this->products).' | 目标: 订单 '.number_format($orders).' / 客户 '.number_format($customers).' / 任务 '.number_format($tasks));

        $this->generateCustomers($customers);
        $this->generateOrders($orders);
        $this->generateTasks($tasks);
        $this->syncSequences();

        $this->info(sprintf('✅ 完成，耗时 %.1f 秒', microtime(true) - $t0));
        $this->table(['表', '行数'], [
            ['customers', number_format(DB::table('customers')->count())],
            ['orders', number_format(DB::table('orders')->count())],
            ['order_items', number_format(DB::table('order_items')->count())],
            ['order_status_logs', number_format(DB::table('order_status_logs')->count())],
            ['refunds', number_format(DB::table('refunds')->count())],
            ['tasks', number_format(DB::table('tasks')->count())],
        ]);

        return 0;
    }

    private function generateCustomers(int $total): void
    {
        $this->info('生成客户...');
        $count = 0;
        $rows = [];
        while ($count < $total) {
            $count++;
            $id = $count;
            $created = $this->randDate('2024-09-01', '2026-08-24');
            $country = $this->weightedKey(self::COUNTRIES_WEIGHTS);
            $currency = $this->weightedKey(self::CURRENCY_WEIGHTS);
            $rows[] = '('.$id.",'".$this->uniqueEmail($id)."','".$this->fullName()."','".$this->phone($country)."','".$country."','".$currency."',0,'".$created."','".$created."')";
            if (count($rows) >= self::BATCH) {
                $this->insertBatch('customers (id, email, name, phone, country, currency, total_spent, created_at, updated_at)', $rows);
                $rows = [];
                $this->progress('客户', $count, $total);
            }
        }
        if ($rows) {
            $this->insertBatch('customers (id, email, name, phone, country, currency, total_spent, created_at, updated_at)', $rows);
        }
    }

    private function generateOrders(int $total): void
    {
        $this->info('生成订单（含明细/状态日志/退款）...');
        $days = $this->dailyCounts($total, '2024-09-01', '2026-08-24', 3200);
        $orderRows = [];
        $itemRows = [];
        $logRows = [];
        $refundRows = [];
        $soldQty = array_fill_keys(array_map(fn ($p) => $p->id, $this->products), 0);

        $cursor = 0;
        foreach ($days as $day => $dayCount) {
            $this->orderSeq = 0;
            $this->refundSeq = 0;
            for ($i = 0; $i < $dayCount; $i++) {
                $cursor++;
                $id = $cursor;
                $this->orderSeq++;
                $status = $this->weightedKey(self::STATUS_WEIGHTS);
                $customerId = random_int(1, $this->customerCount);
                $currency = $this->weightedKey(self::CURRENCY_WEIGHTS);
                $rate = (float) $this->currencies[$currency];
                $factor = (float) $this->currencies['USD'] / $rate;

                $createdAt = $this->randTimeOnDay($day);
                $isPaid = ! in_array($status, ['PENDING_PAYMENT', 'CANCELLED'], true);
                $isShipped = in_array($status, ['SHIPPED', 'COMPLETED', 'REFUNDED', 'REFUNDING'], true);
                $isCompleted = in_array($status, ['COMPLETED', 'REFUNDED'], true);

                // 正推时间线(与状态机一致)
                $paidAt = $isPaid ? $this->addMinutes($createdAt, random_int(3, 180)) : null;
                $shippedAt = $isShipped ? $this->addHours($paidAt, random_int(6, 72)) : null;
                $completedAt = $isCompleted ? $this->addHours($shippedAt, random_int(72, 336)) : null;
                $cancelAt = $status === 'CANCELLED' ? $this->addMinutes($createdAt, random_int(10, 600)) : null;
                $refundCreated = $approvedAt = null;
                if ($status === 'REFUNDING' || $status === 'REFUNDED') {
                    $refundCreated = $status === 'REFUNDING'
                        ? $this->addHours($createdAt, random_int(48, 160))
                        : $this->addHours($shippedAt ?? $paidAt, random_int(48, 400));
                    $approvedAt = $status === 'REFUNDED' ? $this->addHours($refundCreated, random_int(2, 72)) : null;
                }

                // 穿越防护:创建时间靠近今天时,正推的最后一步会落到未来。
                // 检测到穿越则整条时间线平移到过去(最后一步落在最近 24h 内),
                // 相对间隔不变,时间线自洽。
                $timePoints = array_filter([$cancelAt, $paidAt, $shippedAt, $completedAt, $refundCreated, $approvedAt]);
                $lastTs = $timePoints ? max(array_map('strtotime', $timePoints)) : strtotime($createdAt);
                if ($lastTs > time()) {
                    $shift = $lastTs - (time() - random_int(0, 86400));
                    $createdAt = date('Y-m-d H:i:s', strtotime($createdAt) - $shift);
                    foreach (['paidAt', 'shippedAt', 'completedAt', 'cancelAt', 'refundCreated', 'approvedAt'] as $var) {
                        if ($$var !== null) {
                            $$var = date('Y-m-d H:i:s', strtotime($$var) - $shift);
                        }
                    }
                }

                $orderNo = 'CE'.str_replace('-', '', $day).str_pad((string) $this->orderSeq, 4, '0', STR_PAD_LEFT);

                // 明细（金额按汇率换算，公式与 OrderService::create 一致）
                $itemCount = $this->weightedPick([1 => 55, 2 => 33, 3 => 12]);
                $keys = array_rand($this->products, $itemCount);
                if (! is_array($keys)) {
                    $keys = [$keys];
                }
                $total = 0.0;
                $itemSql = [];
                foreach ($keys as $pi) {
                    $p = $this->products[$pi];
                    $qty = random_int(1, 5);
                    $unit = round((float) $p->price * $factor, 2);
                    $total += $unit * $qty;
                    $soldQty[$p->id] += $qty;
                    $itemSql[] = '('.$id.','.$p->id.",'".$p->sku."','".$this->esc($p->name)."',".$qty.','.$unit.')';
                }
                $total = round($total, 2);

                // 状态时间线（与状态机一致）
                $logSql = [];
                $logSql[] = $this->logRow($id, 'NULL', 'PENDING_PAYMENT', '下单', 'agent', $createdAt);
                if ($isPaid) {
                    $logSql[] = $this->logRow($id, 'PENDING_PAYMENT', 'PAID', '支付成功', 'system', $paidAt);
                }
                if ($status === 'CANCELLED') {
                    $logSql[] = $this->logRow($id, 'PENDING_PAYMENT', 'CANCELLED', '取消订单，库存已返还', 'agent', $cancelAt);
                }
                // 运单号只生成一次:物流日志与订单字段复用同一个
                $trackingNo = $isShipped ? $this->trackingNo() : null;
                if ($isShipped) {
                    $logSql[] = $this->logRow($id, 'PAID', 'SHIPPED', '发货，运单号 '.$trackingNo, 'admin', $shippedAt);
                }
                if ($isCompleted) {
                    $logSql[] = $this->logRow($id, 'SHIPPED', 'COMPLETED', '客户签收', 'system', $completedAt);
                }
                if ($status === 'REFUNDING' || $status === 'REFUNDED') {
                    $this->refundSeq++;
                    $refundNo = 'RF'.preg_replace('/[^0-9]/', '', $refundCreated).str_pad((string) $this->refundSeq, 4, '0', STR_PAD_LEFT);
                    $amount = round($total * (float) $this->weightedPick([1.0 => 70, 0.5 => 20, 0.3 => 10]), 2);
                    $before = $this->weightedPick(['PAID' => 30, 'SHIPPED' => 45, 'COMPLETED' => 25]);
                    $reason = self::REFUND_REASONS[array_rand(self::REFUND_REASONS)];
                    $logSql[] = $this->logRow($id, $before, 'REFUNDING', "提交退款申请（{$refundNo}），等待审批", 'agent', $refundCreated);
                    $refundStatus = $status === 'REFUNDED' ? 'approved' : 'pending';
                    $approvedAt = $refundStatus === 'approved' ? $this->addHours($refundCreated, random_int(2, 72)) : null;
                    $refundRows[] = '('.$id.",'".$refundNo."','".$before."',".$amount.",'".$currency."','".$this->esc($reason)."','".$refundStatus."',".$customerId.',1,'.$this->dtOrNull($approvedAt).",'".$refundCreated."','".$refundCreated."')";
                    if ($refundStatus === 'approved') {
                        $logSql[] = $this->logRow($id, 'REFUNDING', 'REFUNDED', "退款审批通过（{$refundNo}）", 'admin', $approvedAt);
                    }
                }

                $logistics = $isCompleted ? 'DELIVERED'
                    : ($isShipped ? ['IN_TRANSIT', 'IN_CUSTOMS', 'OUT_FOR_DELIVERY'][array_rand(['IN_TRANSIT', 'IN_CUSTOMS', 'OUT_FOR_DELIVERY'])]
                    : 'PENDING');
                $trackingSql = $trackingNo !== null ? "'".$trackingNo."'" : 'NULL';
                $orderRows[] = '('.$id.','.$customerId.",'".$orderNo."','".$status."','".$currency."',".$rate.','.$total.','.$total.','.$this->addressSql().','.$trackingSql.",'".$logistics."',".$this->dtOrNull($paidAt).','.$this->dtOrNull($shippedAt).','.$this->dtOrNull($completedAt).",'".$createdAt."','".$createdAt."')";
                $itemRows = array_merge($itemRows, $itemSql);
                $logRows = array_merge($logRows, $logSql);

                if (count($orderRows) >= self::BATCH) {
                    $this->flushOrderBatch($orderRows, $itemRows, $logRows, $refundRows);
                    $orderRows = $itemRows = $logRows = $refundRows = [];
                    $this->progress('订单', $cursor, $total);
                }
            }
        }
        if ($orderRows) {
            $this->flushOrderBatch($orderRows, $itemRows, $logRows, $refundRows);
        }

        // 库存闭环：库存 = 销量 × (1.2~2.5)，保证永不为负且有高/低库存差异
        $stockSql = [];
        foreach ($soldQty as $pid => $qty) {
            $stock = (int) round($qty * (float) $this->weightedPick([1.2 => 30, 1.8 => 40, 2.5 => 30]));
            $stockSql[] = '('.$pid.','.$stock.')';
        }
        DB::statement('UPDATE products AS p SET stock = v.stock, updated_at = now() FROM (VALUES '.implode(',', $stockSql).') AS v(id, stock) WHERE p.id = v.id');
    }

    private function flushOrderBatch(array $orders, array $items, array $logs, array $refunds): void
    {
        DB::transaction(function () use ($orders, $items, $logs, $refunds) {
            $this->insertBatch('orders (id, customer_id, order_no, status, currency, exchange_rate, total_amount, paid_amount, shipping_address, tracking_no, logistics_status, paid_at, shipped_at, completed_at, created_at, updated_at)', $orders);
            if ($items) {
                $this->insertBatch('order_items (order_id, product_id, sku, name, quantity, unit_price)', $items);
            }
            if ($logs) {
                $this->insertBatch('order_status_logs (order_id, from_status, to_status, remark, operator, created_at)', $logs);
            }
            if ($refunds) {
                $this->insertBatch('refunds (order_id, refund_no, order_status_before, amount, currency, reason, status, customer_id, approver_id, approved_at, created_at, updated_at)', $refunds);
            }
        });
    }

    private function generateTasks(int $total): void
    {
        $this->info('生成任务...');
        $rows = [];
        $types = [
            'export:orders' => 50,
            'report:monthly_sales' => 30,
            'report:refund_rate' => 20,
        ];
        for ($i = 1; $i <= $total; $i++) {
            $type = $this->weightedKey($types);
            $status = $this->weightedKey(['success' => 88, 'failed' => 6, 'pending' => 4, 'running' => 2]);
            $created = $this->randDate('2026-06-01', '2026-08-24');
            $finished = in_array($status, ['success', 'failed'], true) ? $this->addMinutes($created, random_int(1, 40)) : null;
            $error = $status === 'failed' ? "'".$this->esc('模拟执行异常：数据聚合超时')."'" : 'NULL';
            $summary = $status === 'success' ? "'".$this->esc(json_encode(['rows' => random_int(50, 2000), 'note' => 'mega-demo'], JSON_UNESCAPED_UNICODE))."'" : 'NULL';
            $rows[] = "('TSK".str_pad((string) $i, 6, '0', STR_PAD_LEFT)."','".$type."','{}','".$status."',".$summary.',NULL,'.$error.',NULL,'.$this->dtOrNull($created).','.$this->dtOrNull($finished).','.$this->dtOrNull($created).')';
            if (count($rows) >= self::BATCH) {
                $this->insertBatch('tasks (task_no, type, params, status, result_summary, result_path, error, created_by, created_at, finished_at, updated_at)', $rows);
                $rows = [];
                $this->progress('任务', $i, $total);
            }
        }
        if ($rows) {
            $this->insertBatch('tasks (task_no, type, params, status, result_summary, result_path, error, created_by, created_at, finished_at, updated_at)', $rows);
        }
    }

    private function syncSequences(): void
    {
        foreach (['customers', 'orders', 'order_items', 'order_status_logs', 'refunds', 'tasks'] as $t) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), (SELECT COALESCE(MAX(id), 1) FROM {$t}))");
        }
    }

    // ---------- helpers ----------

    private function insertBatch(string $cols, array $rows): void
    {
        DB::statement('INSERT INTO '.$cols.' VALUES '.implode(',', $rows));
    }

    private function progress(string $label, int $done, int $total): void
    {
        if ($done % (self::BATCH * 4) === 0 || $done === $total) {
            $this->info(sprintf('  %s %s / %s (%.1f%%)', $label, number_format($done), number_format($total), $done / $total * 100));
        }
    }

    private function logRow(int $orderId, string $from, string $to, string $remark, string $operator, string $at): string
    {
        $fromSql = $from === 'NULL' ? 'NULL' : "'".$from."'";

        return '('.$orderId.','.$fromSql.",'".$to."','".$this->esc($remark)."','".$operator."','".$at."')";
    }

    private function addressSql(): string
    {
        $country = $this->weightedKey(self::COUNTRIES_WEIGHTS);
        $addr = [
            'recipient_name' => $this->fullName(),
            'phone' => $this->phone($country),
            'country' => $country,
            'state' => self::STATE_POOL[array_rand(self::STATE_POOL)],
            'city' => self::CITIES[array_rand(self::CITIES)],
            'address_line1' => self::ADDRESSES[array_rand(self::ADDRESSES)],
            'postal_code' => str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        ];

        return "'".$this->esc(json_encode($addr, JSON_UNESCAPED_UNICODE))."'";
    }

    private function trackingNo(): string
    {
        return 'CE-TRK-'.strtoupper(bin2hex(random_bytes(3)));
    }

    private function trackingNoOrNull(bool $has): string
    {
        return $has ? "'".$this->trackingNo()."'" : 'NULL';
    }

    private function logisticsOrNull(bool $has): string
    {
        return $has ? "'".LogisticsStatus::Pending->value."'" : 'NULL';
    }

    private function dtOrNull(?string $dt): string
    {
        return $dt ? "'".$dt."'" : 'NULL';
    }

    private function uniqueEmail(int $id): string
    {
        return strtolower(self::LAST_NAMES[array_rand(self::LAST_NAMES)]).'.'.self::FIRST_NAMES[array_rand(self::FIRST_NAMES)].'.'.$id.'@example.com';
    }

    private function fullName(): string
    {
        return self::FIRST_NAMES[array_rand(self::FIRST_NAMES)].' '.self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }

    private function phone(string $country): string
    {
        $prefix = self::PHONE_PREFIX[$country] ?? '+1';

        return $prefix.' '.random_int(200, 999).' '.random_int(100, 999).' '.random_int(1000, 9999);
    }

    private function randDate(string $from, string $to): string
    {
        $span = max(1, (strtotime($to) - strtotime($from)) / 60);

        return $this->addMinutes($from, random_int(0, (int) $span));
    }

    private function randTimeOnDay(string $day): string
    {
        return $day.' '.str_pad((string) random_int(0, 23), 2, '0', STR_PAD_LEFT).':'.str_pad((string) random_int(0, 59), 2, '0', STR_PAD_LEFT).':'.str_pad((string) random_int(0, 59), 2, '0', STR_PAD_LEFT);
    }

    private function addMinutes(string $dt, int $mins): string
    {
        return date('Y-m-d H:i:s', strtotime($dt) + $mins * 60);
    }

    private function addHours(string $dt, int $hours): string
    {
        return $this->addMinutes($dt, $hours * 60);
    }

    private function esc(string $s): string
    {
        return str_replace(["\\", "'"], ["\\\\", "''"], $s);
    }

    private function weightedKey(array $weights): string
    {
        $r = random_int(1, (int) array_sum($weights));
        foreach ($weights as $k => $w) {
            $r -= $w;
            if ($r <= 0) {
                return (string) $k;
            }
        }

        return (string) array_key_first($weights);
    }

    private function weightedPick(array $weights)
    {
        $r = random_int(1, (int) array_sum($weights));
        foreach ($weights as $k => $w) {
            $r -= $w;
            if ($r <= 0) {
                return $k;
            }
        }

        return array_key_first($weights);
    }

    /** 按天分配订单数（工作日权重高、周末低，每日上限 cap 保证订单号 4 位序号不溢出） */
    private function dailyCounts(int $total, string $from, string $to, int $cap): array
    {
        $days = [];
        $d = strtotime($from);
        $end = strtotime($to);
        while ($d <= $end) {
            $dow = (int) date('N', $d);
            $days[date('Y-m-d', $d)] = in_array($dow, [6, 7], true) ? 40 : 100;
            $d += 86400;
        }
        $sum = array_sum($days);
        $result = [];
        $assigned = 0;
        foreach ($days as $day => $w) {
            $n = (int) floor($total * $w / $sum);
            $n = min($n, $cap);
            $result[$day] = $n;
            $assigned += $n;
        }
        $remain = $total - $assigned;
        $underCap = array_keys(array_filter($result, fn ($n) => $n < $cap));
        while ($remain > 0 && $underCap) {
            $day = $underCap[array_rand($underCap)];
            $result[$day]++;
            $remain--;
            if ($result[$day] >= $cap) {
                $underCap = array_diff($underCap, [$day]);
            }
        }

        return $result;
    }
}
