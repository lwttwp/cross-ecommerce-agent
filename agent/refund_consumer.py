# -*- coding: utf-8 -*-
"""
方案 B: 退款审批事件消费者。

订阅 RabbitMQ 持久化队列 refund_events(Laravel 审批通过/驳回时发布),
收到事件后组织面向用户的通知文本 —— 事件驱动,Agent 无需轮询/长挂起。

用法:
    .venv\Scripts\python refund_consumer.py

RabbitMQ 连接参数优先取 agent/.env 的 RABBITMQ_* 变量,
默认 127.0.0.1:5673(宿主映射的 docker ce-rabbitmq)。
"""
import io
import json
import os
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))  # agent/
try:
    from dotenv import load_dotenv
    load_dotenv(os.path.join(BASE_DIR, ".env"))
except ImportError:
    pass

import pika  # noqa: E402

QUEUE = os.getenv("RABBITMQ_QUEUE", "refund_events")
HOST = os.getenv("RABBITMQ_HOST", "127.0.0.1")
PORT = int(os.getenv("RABBITMQ_PORT", "5673"))
USER = os.getenv("RABBITMQ_USER", "ce_app")
PASS = os.getenv("RABBITMQ_PASSWORD", "guest")


def on_message(ch, method, properties, body):  # noqa: ANN001
    try:
        event = json.loads(body)
    except json.JSONDecodeError:
        ch.basic_nack(delivery_tag=method.delivery_tag, requeue=False)
        print(f"⚠️  无法解析事件: {body[:100]}")
        return

    result = event.get("result")
    result_cn = "✅ 已审批通过" if result == "approved" else "❌ 已被驳回"
    print("=" * 56)
    print(f"📢 [审批事件] 退款单 {event.get('refund_no')} {result_cn}")
    print(f"   订单号:   {event.get('order_no')}")
    print(f"   金额:     {event.get('amount')} {event.get('currency')}")
    print(f"   审批时间: {event.get('approved_at')}")
    if result == "approved":
        print("   → 通知用户: 您的退款申请已通过,款项将按原支付方式退回")
    else:
        print("   → 通知用户: 您的退款申请被驳回,可联系客服了解原因")
    print("=" * 56, flush=True)

    ch.basic_ack(delivery_tag=method.delivery_tag)


def main() -> None:
    conn = pika.BlockingConnection(pika.ConnectionParameters(
        host=HOST,
        port=PORT,
        credentials=pika.PlainCredentials(USER, PASS),
        heartbeat=30,
    ))
    ch = conn.channel()
    ch.queue_declare(queue=QUEUE, durable=True)
    ch.basic_qos(prefetch_count=1)
    ch.basic_consume(queue=QUEUE, on_message_callback=on_message)
    print(f"👂 监听队列 [{QUEUE}] @ {HOST}:{PORT} (Ctrl+C 退出)")

    try:
        ch.start_consuming()
    except KeyboardInterrupt:
        print("\n退出")
        ch.stop_consuming()
    finally:
        conn.close()


if __name__ == "__main__":
    main()
