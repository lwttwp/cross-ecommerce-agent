from langgraph.graph import MessagesState


class OverAllState(MessagesState):
    # 意图识别(router 产出)
    user_input: str
    intent: str
    intent_reason: str
    order_no: str | None
    # RAG / 工具 / 退款 / 报表(节点产出)
    rag_docs: str
    tool_result: dict
    refund_status: str | None
    task_no: str | None
    # 最终回答
    answer: str