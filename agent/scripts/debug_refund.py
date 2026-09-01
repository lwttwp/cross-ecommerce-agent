# -*- coding: utf-8 -*-
"""检查 test-003 的 checkpoint 状态 + 验证工具调用方式"""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

from cross_ecommerce_agent.graph.build import graph
from cross_ecommerce_agent.tools.business import apply_refund, query_refunds

# 1. 看 test-003 当前 checkpoint 状态
st = graph.get_state({"configurable": {"thread_id": "test-003"}})
print("test-003 state.next:", st.next)          # 非空 = 有挂起中断
print("test-003 messages 条数:", len(st.values.get("messages", [])))
for m in st.values.get("messages", [])[:6]:
    print(f"  {type(m).__name__}: {str(m.content)[:50]}")

# 2. 验证 StructuredTool 调用方式
print("\n--- 工具调用方式测试 ---")
try:
    r = apply_refund("CE202608240494", "测试原因")   # 直接调用
    print("直接调用 OK:", r)
except TypeError as e:
    print("直接调用 TypeError:", e)

try:
    r = apply_refund.invoke({"order_no": "CE202608240494", "reason": "测试原因"})
    print("invoke 调用 OK:", str(r)[:80])
except Exception as e:
    print("invoke 调用失败:", type(e).__name__, str(e)[:120])
