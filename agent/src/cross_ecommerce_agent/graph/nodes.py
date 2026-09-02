from pydantic import BaseModel
import json
from cross_ecommerce_agent.graph.state import OverAllState
from cross_ecommerce_agent.graph.router import IntentRouter,route_by_intent
from cross_ecommerce_agent.rag.retriever import VectorStoreService,HybridRetriever
from cross_ecommerce_agent.rag.query_rewrite import QueryRewriter,need_multi
from cross_ecommerce_agent.config import RAG_ANSWER_PROMPT,\
    AGENT_SYSTEM_PROMPT,EXTRACT_REFUND_PROMPT,REFUND_CONFIRM_THRESHOLD,HISTORY_RECENT_KEEP,\
    HISTORY_SUMMARY_PROMPT
from cross_ecommerce_agent.llm.client import get_llm
from cross_ecommerce_agent.tools.business import query_orders,get_order,create_order,\
    update_order_address,cancel_order,get_tracking,query_products,get_product,\
    get_customer,apply_refund,query_refunds,create_task,get_task,download_task
from langchain_core.messages import HumanMessage,ToolMessage,SystemMessage,AIMessage
from langchain_core.runnables import RunnableConfig
from cross_ecommerce_agent.refund_mapper import record as map_record
from langgraph.graph import END
from langgraph.types import interrupt
import logging
logger = logging.getLogger(__name__)

"""
    START → intent_router ──policy──→ rag_node ──→ END
                  └──query/report/refund──→ agent_node ⇄ tool_node(循环) ──→ END
"""

def build_context(state: OverAllState) -> tuple[list, str]:
    """构造 LLM 输入上下文。

    返回 (保留的原文消息列表, 附加到 system 的文本摘要/未决事项)。
    摘要不塞进 messages 中间(独立 SystemMessage 会被 LLM 当回复内容),
    而是合并进唯一的 system prompt。
    截断对齐回合边界: 避免把 AI(tool_calls) 和它的 ToolMessage 切散,
    否则 OpenAI 兼容 API 报 "Messages with role 'tool' must be a response to
    a preceding message with 'tool_calls'"。
    """
    messages = state.get("messages", [])
    extra = []
    if len(messages) > HISTORY_RECENT_KEEP:
        old = messages[:-HISTORY_RECENT_KEEP]
        recent = messages[-HISTORY_RECENT_KEEP:]
        # 回合边界对齐: recent 首条若是 ToolMessage,补回它在 old 里的 AI(tool_calls)
        while recent and isinstance(recent[0], ToolMessage) and old:
            recent = [old[-1]] + recent
            old = old[:-1]
        # recent 末尾若是孤立的 AI(tool_calls)(其 ToolMessage 还没产生),丢回 old 做摘要
        while recent and isinstance(recent[-1], AIMessage) and recent[-1].tool_calls:
            old = old + [recent[-1]]
            recent = recent[:-1]
        try:
            summary = get_llm(temperature=0).invoke([
                SystemMessage(content=HISTORY_SUMMARY_PROMPT.format(
                    history="\n".join(f"{m.type}: {m.content}" for m in old))),
            ]).content
            extra.append(f"[历史对话摘要] {summary}")
        except Exception:
            extra.append(f"[历史对话已省略 {len(old)} 条]")
    else:
        recent = messages
    # 未处理完成的内容
    if state.get("refund_status"):
        extra.append(f"[未处理事项] {state['refund_status']}")
    return recent, "\n".join(extra)

class RefundArgs(BaseModel):
    order_no: str | None = None
    reason: str | None = None
    amount: float | None = None

def needs_confirm(order: dict, args: RefundArgs) -> bool:
    """异常情况才需要确认:金额问题。"""
    paid = float(order["paid_amount"])
    if args.amount is not None and args.amount > paid:
        return True                      # 退款金额 > 订单金额(明显异常)
    if args.amount is None and paid > REFUND_CONFIRM_THRESHOLD:
        return True                      # 大额全额退款
    return False

_hybrid = HybridRetriever()
_rw = QueryRewriter()
_llm = get_llm()
router = IntentRouter()
tools = [query_orders,get_order,create_order,
    update_order_address,cancel_order,get_tracking,query_products,get_product,
    get_customer,apply_refund,query_refunds,create_task,get_task,download_task]
_llm_with_tools = _llm.bind_tools(tools = tools)
# 意图识别节点
def intent_router(state: OverAllState) -> OverAllState:

    user_input = state["user_input"]
    intent = router.classify(user_input)
    logger.info(f"意图识别: {intent}")
    return {
        "intent": intent.intent,
        "intent_reason": intent.reason,
        "order_no": intent.order_no
    }

def rag_node(state: OverAllState) -> OverAllState:
    # 查询重写
    querys = []
    # 判断是否需要多次改写
    if need_multi(state['user_input']):
        querys = [state['user_input']] + _rw.multi_rewrite(state['user_input'])
    else:
        querys = [_rw.rewrite(state['user_input'])]
    # 查询知识库
    seen, merged = set(), []
    i = 1
    for query in querys:
        rag_doc = _hybrid.invoke(query)
        for res in rag_doc:
            key = res.page_content
            if key not in seen:
                seen.add(key)
                merged.append(f"[{i}] 来源: {res.metadata['source']}\n{res.page_content}")
                i += 1

    merged = "\n\n".join(merged)
    base_msgs, extra_text = build_context(state)
    system_text = RAG_ANSWER_PROMPT + (f"\n\n{extra_text}" if extra_text else "")
    system_msg = SystemMessage(content=system_text)
    # 方案 A:用户消息已在历史中则不重复追加;第一次出现时需一并写入 state
    human_msg = HumanMessage(content=state['user_input'])
    exists = any(isinstance(m, HumanMessage) and m.content == state['user_input'] for m in base_msgs)
    if not exists:
        base_msgs = base_msgs + [human_msg]
    # 参考资料动态变化,每轮附在最后(不进 state)
    messages = [system_msg] + base_msgs + [HumanMessage(content=f"【参考资料】\n{merged}")]
    res = _llm.invoke(messages)
    out = [res]
    if not exists:
        out = [human_msg, res]   # 第一次:用户消息写入 state
    return {
        "rag_docs" : merged,
        "answer" : res.content,
        "messages" : out,
    }

def agent_node(state: OverAllState) -> OverAllState:
    # if state['indent'] == 'refund':

    base_msgs, extra_text = build_context(state)
    system_text = AGENT_SYSTEM_PROMPT + (f"\n\n{extra_text}" if extra_text else "")
    system_msg = SystemMessage(content=system_text)
    messages = base_msgs
    # 用户消息已在历史中则不重复追加;第一次出现时需一并写入 state
    human_msg = HumanMessage(content=state['user_input'])
    exists = any(isinstance(m, HumanMessage) and m.content == state['user_input'] for m in base_msgs)
    if not exists:
        messages = messages + [human_msg]
    messages = [system_msg] + messages
    res = _llm_with_tools.invoke(messages)
    logger.info(f"业务逻辑处理: {res.content}")
    out = [res]
    if not exists:
        out = [human_msg, res]   # 第一次:用户消息写入 state
    return {
        "answer" : res.content,
        "messages" : out,
    }

def latest_refund(refunds):
    items = refunds.get("items", [])
    return max(items, key=lambda r: r["created_at"]) if items else {}

def refund_node(state: OverAllState, config: RunnableConfig) -> OverAllState:
    order_no = state.get('order_no', '')
    # llm抽取参数
    args = extract_refund_args(state["user_input"])
    order_no = state.get("order_no") or args.order_no  # 优先意图分类的,兜底抽取的
    reason = args.reason
    amount = args.amount
    if not order_no:
        return {
            "answer":"请提供订单号,例如:订单 CE202608241024,原因是商品质量问题"
        }
    # 查询订单
    order = get_order.invoke({
        "order_no": order_no,
    })
    if "error" in order:
        return {"answer": f"订单查询失败: {order['error']}"}
    if order["status"] in ("PENDING_PAYMENT", "CANCELLED", "REFUNDED", "REFUNDING"):
        return {"answer": f"订单当前状态({order['status_label']})不支持申请退款"}
    if not reason:
        return {"answer": "请提供退款原因,例如:商品质量问题"}
    if needs_confirm(order, args):
        confirm = interrupt({
            "type": "refund_confirm",  # 用 type 区分挂起点
            "message": "请确认退款申请...",
            "order_no": order_no,
        })
        if confirm.lower() != "y":
            return {"answer": "退款申请已取消"}
    # 业务逻辑处理
    result = apply_refund.invoke({
        "order_no": order["order_no"],
        "reason": reason,
        "amount": amount,
    })
    logger.info(f"退款处理结果: {str(result)}")
    if "error" in result:
        return {"answer": f"退款申请失败: {result['error']}"}
    # 方案 B: 提交后不挂起,审批结果由 RabbitMQ refund_events 事件驱动通知(见 docs 7.3.1)
    refund_no = result.get('refund_no', '')
    # 记录 refund_no -> thread_id,审批事件到达时可定位回原会话
    thread_id = (config.get('configurable') or {}).get('thread_id')
    logger.info(f"DEBUG refund map: refund_no={refund_no!r} thread_id={thread_id!r} has_config={config is not None}")
    if refund_no and thread_id:
        map_record(refund_no, thread_id)
        logger.info(f"退款映射已记录: {refund_no} -> {thread_id}")
    return {"answer": f"退款申请已提交({refund_no}),等待管理员审批,审批结果会第一时间通知您"}

def extract_refund_args(text):
    """
    提取退款相关参数
    """
    prompt = EXTRACT_REFUND_PROMPT.format(text=text)
    llm = get_llm(temperature=0).with_structured_output(RefundArgs)  # 使用默认的LLM实例
    res = llm.invoke([HumanMessage(content=prompt)])
    logger.info(f"提取退款参数: {res}")
    return res


def tool_router(state: OverAllState) -> OverAllState:
    if state['messages'][-1].tool_calls:
        return "tool_node"
    return END

