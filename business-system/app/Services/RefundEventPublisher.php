<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\TraceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * 退款审批事件发布器(方案 B:事件驱动审批)。
 *
 * 审批(approve/reject)完成后把结果发布到 RabbitMQ 持久化队列 refund_events,
 * Agent 侧消费者订阅后异步通知用户 —— 业务系统不感知 AI,只发领域事件。
 *
 * 事件契约(向后兼容地扩展了两个字段,消费端忽略即可):
 *   {refund_no, order_no, result(approved|rejected), amount, currency, approved_at,
 *    trace_id, actor:{id, name, role}}
 *
 * trace_id 让「审批 → 队列 → Agent 通知」这一跳也能回到同一条链路。
 */
class RefundEventPublisher
{
    public const QUEUE = 'refund_events';

    /**
     * @param  array{refund_no: string, order_no: string, result: string, amount: float, currency: string, approved_at: string}  $event
     */
    public function publish(array $event): bool
    {
        $event += [
            'trace_id' => TraceContext::id(),
            'actor' => [
                'id' => TraceContext::userId(),
                'name' => TraceContext::userName(),
                'role' => TraceContext::role(),
            ],
        ];

        $ok = $this->doPublish($event);

        // 事件发布也记一个 span：链路里能看到「事件什么时候发出去的」
        if (config('observability.enabled')) {
            try {
                DB::table('trace_requests')->insert([
                    'trace_id' => $event['trace_id'],
                    'span' => 'event.publish',
                    'channel' => 'event',
                    'method' => null,
                    'path' => 'queue:'.self::QUEUE,
                    'route' => 'refund_events',
                    'status' => $ok ? 200 : 500,
                    'duration_ms' => null,
                    'user_id' => TraceContext::userId(),
                    'user_name' => TraceContext::userName(),
                    'role' => TraceContext::role(),
                    'ip' => null,
                    'session_id' => null,
                    'meta' => json_encode([
                        'refund_no' => $event['refund_no'] ?? null,
                        'result' => $event['result'] ?? null,
                        'trace_origin' => TraceContext::origin(),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('event span 落库失败', ['error' => $e->getMessage()]);
            }
        }

        return $ok;
    }

    private function doPublish(array $event): bool
    {
        $host = (string) env('RABBITMQ_HOST', '127.0.0.1');
        $port = (int) env('RABBITMQ_PORT', 5672);
        $user = (string) env('RABBITMQ_USER', 'guest');
        $pass = (string) env('RABBITMQ_PASSWORD', 'guest');
        $vhost = (string) env('RABBITMQ_VHOST', '/');

        try {
            $conn = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
            $channel = $conn->channel();
            // 持久化队列,消息落盘,服务重启不丢
            $channel->queue_declare(self::QUEUE, passive: false, durable: true, auto_delete: false);

            $msg = new AMQPMessage(
                json_encode($event, JSON_UNESCAPED_UNICODE),
                ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
            );
            $channel->basic_publish($msg, '', self::QUEUE);

            $channel->close();
            $conn->close();

            return true;
        } catch (\Throwable $e) {
            // 事件发布失败不阻断审批主流程,记日志由运维兜底
            logger()->error('退款审批事件发布失败', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
