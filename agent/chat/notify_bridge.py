# -*- coding: utf-8 -*-
"""
通知桥接：RabbitMQ 审批事件 -> 聊天界面 WebSocket 推送（方案 B 闭环升级）。

数据流:
    Laravel 审批(approve/reject)
      -> RabbitMQ refund_events(持久化队列)
      -> 本模块后台线程 pika 消费
      -> ① 写 notifications/{thread_id}.md(保留原行为)
      -> ② 事件进 asyncio.Queue -> pump() 协程 -> ChatManager.broadcast
      -> 聊天界面实时弹出通知条

线程模型（关键）:
    WebSocket.send_text 必须在事件循环里调用;
    pika 消费在独立线程。跨线程用 asyncio.run_coroutine_threadsafe 投递。
"""
import asyncio
import json
import os
import sys
import threading

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # agent/
sys.path.insert(0, os.path.join(BASE_DIR, "src"))

from dotenv import load_dotenv  # noqa: E402
load_dotenv(os.path.join(BASE_DIR, ".env"))

import pika  # noqa: E402
from cross_ecommerce_agent.refund_mapper import lookup  # noqa: E402  # refund_no -> thread_id 映射
from chat.chat_manager import manager  # noqa: E402

QUEUE = os.getenv("RABBITMQ_QUEUE", "refund_events")
HOST = os.getenv("RABBITMQ_HOST", "127.0.0.1")
PORT = int(os.getenv("RABBITMQ_PORT", "5673"))
USER = os.getenv("RABBITMQ_USER", "ce_app")
PASS = os.getenv("RABBITMQ_PASSWORD", "guest")
NOTIFY_DIR = os.path.join(BASE_DIR, "notifications")


# 注意: 不 import refund_consumer —— 它的模块级代码会重包 sys.stdout,
# 与 chat_server 的 TextIOWrapper 共用底层 buffer,先创建的被 GC 时关闭 buffer
# 导致 "I/O operation on closed file"。这里复制 build_notice(逻辑一致)。
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


class ChatEventBridge:
    """RabbitMQ 消费者线程 -> asyncio.Queue -> 事件循环广播。"""

    def __init__(self):
        self._queue: asyncio.Queue = asyncio.Queue()
        self._loop: asyncio.AbstractEventLoop | None = None
        self._thread: threading.Thread | None = None

    # ---------------- 生命周期（chat_server 的 startup/shutdown 调用） ----------------
    def start(self, loop: asyncio.AbstractEventLoop) -> None:
        self._loop = loop
        self._thread = threading.Thread(target=self._consume, daemon=True)
        self._thread.start()
        print("[notify] 审批事件监听线程已启动")

    def stop(self) -> None:
        # daemon 线程随进程退出,无需强停;保留钩子便于扩展优雅关闭
        pass

    # ---------------- 事件循环侧：消费队列并广播到对应会话 ----------------
    async def pump(self) -> None:
        while True:
            payload = await self._queue.get()
            thread_id = payload.pop("thread_id", None)
            if thread_id:
                try:
                    n = await manager.broadcast(thread_id, payload)
                    if n == 0:
                        print(f"[notify] 会话 {thread_id} 无在线连接,通知仅落文件")
                except Exception as e:  # noqa: BLE001
                    print(f"[notify] 广播失败: {e}")

    # ---------------- 后台线程侧：pika 消费（照抄 refund_consumer.py 的连接方式） ----------------
    def _consume(self) -> None:
        def on_message(ch, method, properties, body):
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

            thread_id = lookup(refund_no) if refund_no else None
            if thread_id:
                # ① 保留原行为: 写通知文件
                try:
                    os.makedirs(NOTIFY_DIR, exist_ok=True)
                    with open(os.path.join(NOTIFY_DIR, f"{thread_id}.md"), "a", encoding="utf-8") as f:
                        f.write(text + "\n")
                except OSError as e:
                    print(f"[notify] 写文件失败: {e}")
                # ② 推送聊天界面（跨线程投递到事件循环）
                self._push({"thread_id": thread_id, "type": "notice", "content": text.strip()})
            else:
                print("ℹ️  无会话映射(可能非 Agent 提交的退款),仅打印通知")
            print("=" * 56, flush=True)
            ch.basic_ack(delivery_tag=method.delivery_tag)

        try:
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
            print(f"👂 监听审批事件队列 [{QUEUE}] @ {HOST}:{PORT} (Ctrl+C 退出)")
            ch.start_consuming()
        except Exception as e:  # noqa: BLE001
            print(f"[notify] 消费线程异常: {e}")

    # ---------------- 跨线程投递：pika 线程 -> asyncio 队列 ----------------
    def _push(self, payload: dict) -> None:
        if self._loop is None:
            return
        asyncio.run_coroutine_threadsafe(self._queue.put(payload), self._loop)
