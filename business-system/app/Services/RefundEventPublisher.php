<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * 退款审批事件发布器(方案 B:事件驱动审批)。
 *
 * 审批(approve/reject)完成后把结果发布到 RabbitMQ 持久化队列 refund_events,
 * Agent 侧消费者订阅后异步通知用户 —— 业务系统不感知 AI,只发领域事件。
 *
 * 事件契约: {refund_no, order_no, result(approved|rejected), amount, currency, approved_at}
 */
class RefundEventPublisher
{
    public const QUEUE = 'refund_events';

    /**
     * @param  array{refund_no: string, order_no: string, result: string, amount: float, currency: string, approved_at: string}  $event
     */
    public function publish(array $event): bool
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
