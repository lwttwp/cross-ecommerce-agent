<?php

declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\BusinessApiClient;

/**
 * 业务系统工具定义:名称/描述/JSON Schema 参数/处理函数。
 * 与 agent/tools/business.py 的 14 个 LangChain 工具一一对应。
 */
final class BusinessTools
{
    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>, handler: callable}> */
    public static function build(BusinessApiClient $api): array
    {
        $str = static fn (string $desc = ''): array => ['type' => 'string', 'description' => $desc];
        $int = static fn (string $desc = ''): array => ['type' => 'integer', 'description' => $desc];

        return [
            // ==================== 订单 ====================
            [
                'name' => 'query_orders',
                'description' => '查询订单列表,支持订单号/状态/关键词/日期范围/币种过滤,分页返回。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'order_no' => $str('订单号,格式 CE+14位数字,精确匹配'),
                        'status' => $str('订单状态: PENDING_PAYMENT/PAID/SHIPPED/COMPLETED/CANCELLED/REFUNDING/REFUNDED'),
                        'keyword' => $str('模糊搜索: 订单号/客户名/邮箱'),
                        'date_from' => $str('创建时间起,YYYY-MM-DD(北京时间)'),
                        'date_to' => $str('创建时间止,YYYY-MM-DD(北京时间)'),
                        'currency' => $str('币种,如 USD/EUR,大写'),
                        'page' => $int('页码,默认 1'),
                        'page_size' => $int('每页条数,默认 20,最大 100'),
                    ],
                ],
                'handler' => static fn (array $a) => $api->get('/orders', $a),
            ],
            [
                'name' => 'get_order',
                'description' => '查询单个订单详情(含商品明细、金额、时间线、折合人民币金额)。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['order_no' => $str('订单号,格式 CE+14位数字')],
                    'required' => ['order_no'],
                ],
                'handler' => static fn (array $a) => $api->get('/orders/' . ($a['order_no'] ?? '')),
            ],
            [
                'name' => 'create_order',
                'description' => '创建订单(写操作,事务扣库存),下单成功后状态为待支付。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => $int('客户ID(可从 query_customer 获得)'),
                        'currency' => $str('币种,如 USD'),
                        'items' => ['type' => 'array', 'description' => '商品列表,每项 {sku, quantity}',
                            'items' => ['type' => 'object']],
                        'shipping_address' => ['type' => 'object',
                            'description' => '收货地址: recipient_name/phone/country(两位码)/city/address_line1/postal_code,state 可选'],
                    ],
                    'required' => ['customer_id', 'currency', 'items', 'shipping_address'],
                ],
                'handler' => static fn (array $a) => $api->post('/orders', [
                    'customer_id' => $a['customer_id'] ?? null,
                    'currency' => $a['currency'] ?? null,
                    'items' => $a['items'] ?? [],
                    'shipping_address' => $a['shipping_address'] ?? [],
                ]),
            ],
            [
                'name' => 'update_order_address',
                'description' => '修改订单收货地址(写操作,仅未发货订单可改)。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'order_no' => $str('订单号,格式 CE+14位数字'),
                        'address' => ['type' => 'object',
                            'description' => '新地址: recipient_name/phone/country/city/address_line1/postal_code,state 可选'],
                    ],
                    'required' => ['order_no', 'address'],
                ],
                'handler' => static fn (array $a) => $api->post('/orders/' . ($a['order_no'] ?? '') . '/address',
                    $a['address'] ?? []),
            ],
            [
                'name' => 'cancel_order',
                'description' => '取消订单(写操作,仅待支付 PENDING_PAYMENT 状态可取消)。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['order_no' => $str('订单号,格式 CE+14位数字')],
                    'required' => ['order_no'],
                ],
                'handler' => static fn (array $a) => $api->post('/orders/' . ($a['order_no'] ?? '') . '/cancel'),
            ],
            [
                'name' => 'get_tracking',
                'description' => '查询订单物流轨迹。状态枚举: PENDING(待揽收)/IN_TRANSIT(运输中)/IN_CUSTOMS(清关中)/OUT_FOR_DELIVERY(派送中)/DELIVERED(已签收)。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['order_no' => $str('订单号,格式 CE+14位数字')],
                    'required' => ['order_no'],
                ],
                'handler' => static fn (array $a) => $api->get('/orders/' . ($a['order_no'] ?? '') . '/tracking'),
            ],

            // ==================== 商品 ====================
            [
                'name' => 'query_products',
                'description' => '查询商品列表,支持 SKU/关键词/类目/状态过滤,分页。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => $str('商品编码,如 SKU-1001'),
                        'keyword' => $str('名称关键词'),
                        'category' => $str('类目'),
                        'status' => $str('状态: on/off'),
                        'page' => $int('页码,默认 1'),
                        'page_size' => $int('每页条数,默认 20,最大 100'),
                    ],
                ],
                'handler' => static fn (array $a) => $api->get('/products', $a),
            ],
            [
                'name' => 'get_product',
                'description' => '查询单个商品详情(含库存)。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['sku' => $str('商品编码,如 SKU-1001')],
                    'required' => ['sku'],
                ],
                'handler' => static fn (array $a) => $api->get('/products/' . ($a['sku'] ?? '')),
            ],
            [
                'name' => 'query_customer',
                'description' => '查询客户信息(含消费统计),手机号脱敏。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['customer_id' => $int('客户ID')],
                    'required' => ['customer_id'],
                ],
                'handler' => static fn (array $a) => $api->get('/customers/' . ($a['customer_id'] ?? '')),
            ],

            // ==================== 退款 ====================
            [
                'name' => 'apply_refund',
                'description' => '申请退款(写操作,需人工审批)。校验订单状态与金额,创建 pending 退款单。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'order_no' => $str('订单号,格式 CE+14位数字'),
                        'reason' => $str('退款原因'),
                        'amount' => ['type' => 'number', 'description' => '退款金额,不传则全额退款'],
                    ],
                    'required' => ['order_no', 'reason'],
                ],
                'handler' => static fn (array $a) => $api->post('/orders/' . ($a['order_no'] ?? '') . '/refunds', [
                    'reason' => $a['reason'] ?? '',
                    'amount' => $a['amount'] ?? null,
                ]),
            ],
            [
                'name' => 'query_refunds',
                'description' => '查询退款单列表,支持单号/订单号/状态过滤。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'refund_no' => $str('退款单号'),
                        'order_no' => $str('订单号'),
                        'status' => $str('状态: pending/approved/rejected'),
                    ],
                ],
                'handler' => static fn (array $a) => $api->get('/refunds', $a),
            ],

            // ==================== 报表任务 ====================
            [
                'name' => 'create_task',
                'description' => '创建异步报表/导出任务,立即返回 task_no,结果需轮询 get_task。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_type' => $str('任务类型: report:monthly_sales / report:refund_rate / export:orders'),
                        'params' => ['type' => 'object', 'description' => '参数: date_from/date_to(YYYY-MM-DD) 等'],
                    ],
                    'required' => ['task_type'],
                ],
                'handler' => static fn (array $a) => $api->post('/tasks', [
                    'type' => $a['task_type'] ?? '',
                    'params' => $a['params'] ?? [],
                ]),
            ],
            [
                'name' => 'get_task',
                'description' => '查询异步任务状态与结果摘要。状态: pending/running/success/failed。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['task_no' => $str('任务号,如 TSK0001')],
                    'required' => ['task_no'],
                ],
                'handler' => static fn (array $a) => $api->get('/tasks/' . ($a['task_no'] ?? '')),
            ],
            [
                'name' => 'download_task',
                'description' => '下载异步任务产物(CSV 文本)。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['task_no' => $str('任务号,如 TSK0001')],
                    'required' => ['task_no'],
                ],
                'handler' => static fn (array $a) => ['csv' => $api->getText('/tasks/' . ($a['task_no'] ?? '') . '/download')],
            ],
        ];
    }
}
