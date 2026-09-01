# -*- coding: utf-8 -*-
"""
演示脚本:依次演示 Agent 的 5 类核心能力。

用法:
    .venv\Scripts\python demo.py

演示内容:
    1. 订单查询   2. 物流跟踪   3. 政策问答(RAG)   4. 报表任务(异步)
    5. 退款申请(human-in-the-loop: 提交前确认中断)

注意:
- 第 5 步若输入 y 会真实提交退款申请(订单状态变为 REFUNDING),
  重复演示时请更换订单号(可用任意 SHIPPED/PAID 状态的大额订单)
- 每步使用独立 thread_id,会话互不干扰
"""
import io
import os
import sys
import uuid

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(BASE_DIR, "src"))

try:
    from dotenv import load_dotenv
    load_dotenv(os.path.join(BASE_DIR, ".env"))
except ImportError:
    pass

from cross_ecommerce_agent.graph.build import graph  # noqa: E402
from langgraph.types import Command  # noqa: E402

CASES = [
    ("1. 订单查询", "查一下 CE202608241027 这个订单"),
    ("2. 物流跟踪", "订单 CE202608241027 到哪了"),
    ("3. 政策问答", "发美国的订单关税谁承担"),
    ("4. 报表任务", "生成 6 月的销售报表"),
]


def show(title: str, text: str, thread: str) -> dict:
    print("=" * 64)
    print(f"{title}\n🧑 用户: {text}")
    config = {"configurable": {"thread_id": thread}}
    res = graph.invoke({"user_input": text}, config=config)
    if "__interrupt__" in res:
        print("⏸️ 流程在等待人工输入(interrupt)")
        return res
    print(f"🤖 助手: {res.get('answer')}\n")
    return res


if __name__ == "__main__":
    for title, text in CASES:
        show(title, text, "demo-" + uuid.uuid4().hex[:8])

    print("=" * 64)
    print("5. 退款申请(方案 B: 确认中断 → 提交 → 事件驱动审批)")
    text = "订单 CE202502201150 申请退款，不想要了"
    config = {"configurable": {"thread_id": "demo-refund"}}
    res = graph.invoke({"user_input": text}, config=config)
    if "__interrupt__" in res:
        value = res["__interrupt__"][0].value
        print(f"⏸️ 中断[{value.get('type')}]: 请确认退款申请")
        ans = input("   是否确认提交退款申请? (y/n) > ").strip().lower()
        res = graph.invoke(Command(resume=ans), config=config)
    if "__interrupt__" in res:
        print("⚠️ 意外中断:", [i.value.get('type') for i in res['__interrupt__']])
    else:
        print(f"🤖 助手: {res.get('answer')}")
        print("   (方案 B: 提交后不挂起,审批结果由 RabbitMQ refund_events 事件驱动通知)")
        print("   提示: 用 admin 审批该退款单后,运行 refund_consumer.py 可看到实时通知")
    print("\n演示结束 ✅")
