# -*- coding: utf-8 -*-
"""
聊天服务入口（FastAPI）: 包住 LangGraph 图,提供 WebSocket 聊天端点 + 静态前端。

运行:
    cd agent
    .venv\\Scripts\\python chat_server.py
    # 浏览器打开 http://127.0.0.1:8082

依赖（requirements.txt 新增）:
    fastapi  uvicorn  websockets

协议（与 static/index.html 约定,勿改字段名）:
    客户端 -> 服务端: {"type":"chat","message":"..."} | {"type":"resume","value":"y"|"n"}
    服务端 -> 客户端: ready / history / token / message / interrupt / notice / error
"""
import io
import os
import sys
import asyncio
from contextlib import asynccontextmanager

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
BASE_DIR = os.path.dirname(os.path.abspath(__file__))          # agent/
sys.path.insert(0, os.path.join(BASE_DIR, "src"))
sys.path.insert(0, BASE_DIR)                                    # 使 `from chat.xxx import ...` 可用

from dotenv import load_dotenv
load_dotenv(os.path.join(BASE_DIR, ".env"))

from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.staticfiles import StaticFiles

from chat.chat_manager import manager
from chat.notify_bridge import ChatEventBridge
from langgraph.types import Command
from langchain_core.messages import AIMessage, HumanMessage

# LangGraph 图（import 即建 checkpointer,需要 PG 在线）
from cross_ecommerce_agent.graph.build import graph


# ============================================================
# 历史消息: 从 checkpointer 读该 thread 的完整对话(过滤工具消息)
# ============================================================
def load_history(session_id: str) -> list[dict]:
    """返回 [{role: user|assistant, content}, ...]，供会话切换时渲染。"""
    thread_id = manager.get_thread_id(session_id)
    if not thread_id:
        return []
    try:
        state = graph.get_state({"configurable": {"thread_id": thread_id}})
    except Exception:
        return []
    out = []
    for m in (state.values.get("messages") or []):
        if isinstance(m, HumanMessage) and m.content:
            out.append({"role": "user", "content": m.content})
        elif isinstance(m, AIMessage) and m.content and not m.tool_calls:
            out.append({"role": "assistant", "content": m.content})
    return out


def run_graph_sync(graph_input, config):
    """同步流式执行图（在线程池里跑）：产出 (mode, data) 帧序列。

    为什么不用 graph.astream：图的 checkpointer 是同步 PostgresSaver，
    异步 API 会抛 NotImplementedError（aget_tuple 未实现）。
    所以用同步 graph.stream + asyncio.to_thread 桥接。
    """
    for mode, data in graph.stream(
        graph_input, config, stream_mode=["messages", "values"]
    ):
        yield mode, data


async def run_graph_and_stream(websocket, graph_input, config):
    """桥接执行：同步图流 -> asyncio.Queue -> WebSocket 帧。

    producer 在后台线程跑同步流式,帧放进队列;
    本协程边收边发,保证打字机效果。
    """
    queue: asyncio.Queue = asyncio.Queue()

    def producer():
        try:
            for mode, data in run_graph_sync(graph_input, config):
                queue.put_nowait(("frame", mode, data))
        except Exception as e:                 # 线程里捕获异常,经队列传递
            queue.put_nowait(("error", f"{type(e).__name__}: {e}", None))
        finally:
            queue.put_nowait(("done", None, None))

    # 后台线程跑生产者(不 await 它跑完,边生产边消费才有流式效果)
    loop = asyncio.get_running_loop()
    loop.run_in_executor(None, producer)

    final_answer = ""
    while True:
        item = await queue.get()
        kind = item[0]
        mode = item[1] if len(item) > 1 else None
        data = item[2] if len(item) > 2 else None
        if kind == "done":
            break
        if kind == "error":
            err = mode or data or "未知错误"     # 异常消息在帧第 2 位(mode)
            print(f"[chat] 图执行异常: {err}")   # 打到服务端控制台,方便定位
            await websocket.send_json({"type": "error", "message": f"服务异常: {err}"})
            return
        if mode == "messages":
            msg_chunk, _meta = data
            # 只流 AI 的正文文本；工具调用/工具返回的原始 JSON 不显示
            if isinstance(msg_chunk, AIMessage) and not msg_chunk.tool_calls:
                text = msg_chunk.content
                if text:
                    await websocket.send_json({"type": "token", "content": text})
        elif mode == "values":
            if "__interrupt__" in data:        # 图挂起（退款确认等）
                value = data["__interrupt__"][0].value
                await websocket.send_json({"type": "interrupt", "data": value})
                return                          # 中断后不再发 message 帧
            if data.get("answer"):             # 记录最新回答（收尾用）
                final_answer = data["answer"]

    # 流正常结束（无中断）
    await websocket.send_json({"type": "message", "content": final_answer, "done": True})

bridge = ChatEventBridge()


@asynccontextmanager
async def lifespan(app: FastAPI):
    """启动/关闭钩子（FastAPI 官方推荐写法,替代已移除的 add_event_handler）。"""
    # 启动: RabbitMQ 消费线程 + 事件循环里的广播任务
    bridge.start(asyncio.get_running_loop())
    pump_task = asyncio.create_task(bridge.pump())
    yield
    # 关闭
    bridge.stop()
    pump_task.cancel()


app = FastAPI(title="cross-ecommerce-agent chat", lifespan=lifespan)

# 注意: 静态挂载必须放在所有路由(尤其 /ws)定义之后,
# 否则 mount("/") 会拦截 WebSocket 请求导致 500(见文件末尾)


# ============================================================
# 会话 HTTP API（前端切换/新建会话用,与 WS 协议分离）
# ============================================================
@app.get("/api/sessions")
async def api_list_sessions():
    """会话列表(按最后活跃倒序)。"""
    return {"code": 0, "data": manager.list_sessions()}


@app.post("/api/sessions")
async def api_create_session():
    """新建会话。"""
    return {"code": 0, "data": manager.create_session()}


@app.get("/api/sessions/{session_id}/messages")
async def api_session_messages(session_id: str):
    """某会话的历史消息(checkpointer 读取)。"""
    return {"code": 0, "data": load_history(session_id)}


@app.websocket("/ws")
async def ws_chat(websocket: WebSocket) -> None:
    await websocket.accept()

    # 1. session_id -> thread_id（断线重连、刷新页面后继续同一会话）
    session_id = websocket.query_params.get("session_id", "")
    thread_id = manager.get_thread_id(session_id)
    if not thread_id:
        # 容错: 前端正常流程先 POST /api/sessions 再连 WS,这里兜底自动建会话
        info = manager.create_session()
        session_id = info["session_id"]
        thread_id = info["thread_id"]
    manager.register(thread_id, websocket)
    await websocket.send_json({"type": "ready", "session_id": session_id})

    config = {"configurable": {"thread_id": thread_id}}

    try:
        while True:
            raw = await websocket.receive_text()
            msg = json_loads_safe(raw)
            if not msg:
                continue

            if msg["type"] == "chat":
                # 更新活跃时间;首条消息自动命名会话标题
                manager.touch(session_id, title=msg["message"])
                # ---- 流式调图：token 打字 + 中断检测 + 最终回答 ----
                await run_graph_and_stream(websocket, {"user_input": msg["message"]}, config)

            elif msg["type"] == "resume":
                # ---- 恢复被 interrupt 挂起的图（退款确认等） ----
                await run_graph_and_stream(websocket, Command(resume=msg["value"]), config)

    except WebSocketDisconnect:
        pass
    finally:
        manager.unregister(thread_id, websocket)


def json_loads_safe(raw: str):
    import json
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return None


# ============================================================
# 第 4 步: 审批事件 -> 聊天界面（启动/关闭钩子在文件顶部的 lifespan）
# ============================================================

# ---- 静态前端挂载(必须放最后): http://127.0.0.1:8082/ -> agent/chat/static/index.html ----
app.mount("/", StaticFiles(directory=os.path.join(BASE_DIR, "chat", "static"), html=True), name="static")


if __name__ == "__main__":
    import uvicorn
    # log_config=None: uvicorn 默认日志配置与 Python 3.14 logging 不兼容,
    # 改用 Python 默认日志(启动/访问日志仍会打印,不影响功能)
    uvicorn.run(app, host="0.0.0.0", port=8082, log_config=None)   # 0.0.0.0: 容器内也需监听
