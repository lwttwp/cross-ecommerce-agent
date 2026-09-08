# -*- coding: utf-8 -*-
"""
多 Agent 专家层: 订单/售后/报表 三个子 Agent(ReAct),包装成工具供 supervisor 路由。

每个专家: 独立 system prompt + 自己的小工具集(14 工具全绑一个 LLM 容易选错,
拆分后每个 LLM 只面对 3-8 个工具,工具选择更准)。专家无 checkpointer,每次独立执行,
多轮记忆由 supervisor 层(主图 checkpointer)维护。
"""
from langchain_core.tools import tool
from langgraph.prebuilt import create_react_agent

from cross_ecommerce_agent.llm.client import get_llm
from cross_ecommerce_agent.tools.business import (
    query_orders, get_order, create_order,
    update_order_address, cancel_order, get_tracking,
    query_products, get_product, get_customer,
    query_refunds, create_task, get_task, download_task,
)

# ---------------- 工具分组 ----------------
ORDER_TOOLS = [query_orders, get_order, get_tracking, update_order_address,
               cancel_order, query_products, get_product, get_customer]
AFTERSALE_TOOLS = [query_refunds]
REPORT_TOOLS = [create_task, get_task, download_task]

# ---------------- 专家 prompt ----------------
ORDER_PROMPT = """你是跨境电商平台的【订单专家】,处理订单/物流/商品/客户相关请求。
工作准则:
1. 查询必须调用工具获取真实数据,禁止猜测或编造
2. 写操作(创建订单/修改地址/取消订单)执行前必须先向用户复述确认关键参数
3. 工具返回 error 时如实告知原因
4. 订单号/金额/状态等关键信息必须与工具返回完全一致
5. 只处理订单域问题;涉及退款申请、售后政策时,告知用户需要走退款申请流程"""

AFTERSALE_PROMPT = """你是跨境电商平台的【售后专家】,只负责查询退款单状态与售后进度。
工作准则:
1. 查询必须调用工具获取真实数据
2. 不能发起退款申请(退款申请有专门的确认审批流程),用户要求申请退款时,
   请用户提供订单号与原因后走申请流程
3. 只处理售后查询域;订单物流/商品等问题转订单专家"""

REPORT_PROMPT = """你是跨境电商平台的【报表专家】,负责报表/导出任务。
工作准则:
1. 创建任务前确认参数(报表类型、月份/日期范围)
2. 任务创建成功告知任务编号,说明稍后可查询结果
3. 查询任务状态用工具获取真实数据,任务未完成如实说明
4. 任务成功后提供下载链接"""

# ---------------- 构建专家(ReAct 子图) ----------------
_llm = get_llm()

order_agent = create_react_agent(_llm, ORDER_TOOLS, prompt=ORDER_PROMPT)
aftersale_agent = create_react_agent(_llm, AFTERSALE_TOOLS, prompt=AFTERSALE_PROMPT)
report_agent = create_react_agent(_llm, REPORT_TOOLS, prompt=REPORT_PROMPT)


# ---------------- 包装成工具(主管视角: 每个专家 = 一个工具) ----------------
def _make_tool(name: str, desc: str, agent):
    @tool(name, description=desc)
    def run(query: str) -> str:
        """调用对应专家处理(query 为完整请求,含必要上下文)。"""
        res = agent.invoke({"messages": [("user", query)]})
        msgs = res.get("messages", [])
        return msgs[-1].content if msgs else "专家无返回"
    return run


agent_tools = [
    _make_tool("order_agent", (
        "订单专家: 查询订单/物流轨迹/商品/客户信息,修改地址,取消订单,创建订单。"
        "当用户请求涉及订单状态、物流、商品、客户时调用,query 传完整请求(含订单号等关键信息)。"),
        order_agent),
    _make_tool("aftersale_agent", (
        "售后专家: 查询退款单状态/售后进度。当用户询问退款进度、退款单状态、售后处理情况时调用。"
        "注意: 用户要发起退款申请不调本专家(走专门申请流程)。"),
        aftersale_agent),
    _make_tool("report_agent", (
        "报表专家: 创建/查询报表导出任务、获取下载链接。当用户要生成报表、导出订单、下载文件时调用。"),
        report_agent),
]
