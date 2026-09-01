from langgraph.graph import StateGraph, START, END
from cross_ecommerce_agent.graph.nodes import intent_router,rag_node,agent_node,\
    tools,tool_router,refund_node
from cross_ecommerce_agent.graph.state import OverAllState
from cross_ecommerce_agent.graph.router import route_by_intent
from langgraph.prebuilt import ToolNode
from cross_ecommerce_agent.config import CHECKPOINT_DB_DSN
from langgraph.checkpoint.postgres import PostgresSaver
from psycopg_pool import ConnectionPool
import psycopg

_pool = ConnectionPool(
    conninfo = CHECKPOINT_DB_DSN,
    min_size = 1,
    max_size = 15,
    open = False,
)

def make_checkpointer():
    # setup() 里有 CREATE INDEX CONCURRENTLY,不能在事务内执行 → 用独立 autocommit 连接建表
    with psycopg.connect(CHECKPOINT_DB_DSN, autocommit=True) as conn:
        PostgresSaver(conn).setup()
    _pool.open()
    return PostgresSaver(_pool)

builder = StateGraph(state_schema=OverAllState)

builder.add_node("intent_router", intent_router)
builder.add_node("rag_node", rag_node)
builder.add_node("agent_node", agent_node)
builder.add_node("refund_node", refund_node)
builder.add_node("tool_node", ToolNode(tools=tools))


builder.add_edge(START, "intent_router")
builder.add_conditional_edges(
    "intent_router",
    route_by_intent,
    path_map={
        "policy": "rag_node",
        "query": "agent_node",
        "refund": "refund_node",
        "report": "agent_node",
    }
)
builder.add_conditional_edges(
    "agent_node",
            tool_router
    )
builder.add_edge("tool_node", "agent_node")
builder.add_edge("rag_node", END)
builder.add_edge("refund_node", END)

graph = builder.compile(checkpointer=make_checkpointer())


if __name__ == "__main__":
    from langgraph.types import Command
    config = {
        "configurable": {
            "thread_id":"test-007"
        }
    }
    from IPython.display import display
    print(graph.get_graph().draw_ascii())
    # res = graph.invoke({"user_input":"礼品卡买了不想要能退钱吗"})
    # for msg in res['messages']:
    #     msg.pretty_print()

    res = graph.invoke({"user_input": "SKU-1001还有多少库存"}, config=config)
    print(res)
    print("=" * 60)
    if "__interrupt__" in res:
       for val in res["__interrupt__"]:
           if val.value["type"] == "refund_confirm":
               result = input("请确认退款申请？（y/n）")
               approved_res = graph.invoke(Command(resume=result), config=config)
               print(approved_res)

# res = graph.invoke({"user_input": "查一下 SKU-1028 这个商品"})
    # print(res)
    # print("=" * 60)
    # for msg in res['messages']:
    #     msg.pretty_print()
