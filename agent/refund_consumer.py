# -*- coding: utf-8 -*-
"""
方案 B: 退款审批事件消费者(含会话回定位)。

订阅 RabbitMQ 持久化队列 refund_events(Laravel 审批通过/驳回时发布),
按 refund_no 查 thread_id 映射,把通知投递到对应会话的通知区文件。

投递形态(演示): agent/notifications/{thread_id}.md 追加通知文本。
生产建议: 换成 WebSocket/SSE 推送或消息直接进会话通道,映射存 Redis。

用法:
    .venv\Scripts\python refund_consumer.py
"""
import io
import json
import os
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))  # agent/
sys.path.insert(0, os.path.join(BASE_DIR, "src"))
NOTIFY_DIR = os.path.join(BASE_DIR, "notifications")

try:
    from dotenv import load_dotenv
    load_dotenv(os.path.join(BASE_DIR, ".env"))
except ImportError:
    pass

import pika  # noqa: E402
from cross_ecommerce_agent.refund_mapper import lookup  # noqa: E402

QUEUE = os.getenv("RABBITMQ_QUEUE", "refund_events")
HOST = os.getenv("RABBITMQ_HOST", "127.0.0.1")
PORT = int(os.getenv("RABBITMQ_PORT", "5673"))
USER = os.getenv("RABBITMQ_USER", "ce_app")
PASS = os.getenv("RABBITMQ_PASSWORD", "guest")


def build_notice(event: dict) -> str:
    result = event.get("result")
    result_cn = "✅ 已审批通过" if result == "approved" else "❌ 已被驳回"
    lines = [
        f"📢 [审批事件] 退款单 {event.get('refund_no')} {result_cn}",
        f"   订单号:   {event.get('order_no')}",
        f"   金额:     {event.get('amount')} {event.get('currency')}",
        f"   审批时间: {event.get('approved_at')}",
    ]
    if result == "approved":
        lines.append("   → 您的退款申请已通过,款项将按原支付方式退回")
    else:
        lines.append("   → 您的退款申请被驳回,可联系客服了解原因")
    return "\n".join(lines) + "\n"


def deliver(thread_id: str, text: str) -> str:
    """把通知投递到会话通知区,返回文件路径。"""
    os.makedirs(NOTIFY_DIR, exist_ok=True)
    path = os.path.join(NOTIFY_DIR, f"{thread_id}.md")
    with open(path, "a", encoding="utf-8") as f:
        f.write(text + "\n")
    return path


def on_message(ch, method, properties, body):  # noqa: ANN001
    try:
        event = json.loads(body)
    except json.JSONDecodeError:
        ch.basic_nack(delivery_tag=method.delivery_tag, requeue=False)
        print(f"⚠️  无法解析事件: {body[:100]}")
        return

    refund_no = event.get("refund_no")
    text = build_notice(event)
    print("=" * 56)
    print(text.strip())

    # 回定位会话: refund_no -> thread_id
    thread_id = lookup(refund_no) if refund_no else None
    if thread_id:
        path = deliver(thread_id, text)
        print(f"📨 已投递到会话 [{thread_id}]: {path}")
    else:
        print("ℹ️  无会话映射(可能非 Agent 提交的退款),仅打印通知")
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
