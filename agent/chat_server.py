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
    服务端 -> 客户端: ready / token / message / interrupt / notice / error

多用户与可观测（v1.1）:
    - 每个业务请求/连接都校验 Laravel session（见 chat/auth.py），未登录 401 / WS 关闭码 4401；
      /metrics 例外：走独立的 METRICS_TOKEN，未配置时返回 403；
    - 会话按员工归属，越权 HTTP 403 / WS 4403（见 chat/chat_manager.py）；
    - 每条消息生成 trace_id，贯穿图执行、工具调用与业务 API（见 cross_ecommerce_agent/obs.py）。
"""
import io
import os
import sys
import asyncio
import logging
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from contextlib import asynccontextmanager

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
BASE_DIR = os.path.dirname(os.path.abspath(__file__))          # agent/
sys.path.insert(0, os.path.join(BASE_DIR, "src"))
sys.path.insert(0, BASE_DIR)                                    # 使 `from chat.xxx import ...` 可用

from dotenv import load_dotenv
load_dotenv(os.path.join(BASE_DIR, ".env"))

# 日志必须早于 graph 的 import（config.py 会 basicConfig，已有 handler 时它不会覆盖）
from cross_ecommerce_agent import obs                            # noqa: E402

obs.configure_logging(os.path.join(BASE_DIR, "logs"))
log = logging.getLogger("agent")

from fastapi import Depends, FastAPI, HTTPException, Request, WebSocket, WebSocketDisconnect  # noqa: E402
from fastapi.responses import JSONResponse, PlainTextResponse  # noqa: E402
from fastapi.staticfiles import StaticFiles  # noqa: E402

from chat.auth import ChatUser, auth, extract_cookie  # noqa: E402
from chat.chat_manager import NotOwnerError, manager  # noqa: E402
from chat.notify_bridge import ChatEventBridge  # noqa: E402
from langgraph.types import Command  # noqa: E402
from langchain_core.messages import AIMessage, HumanMessage  # noqa: E402

import cross_ecommerce_agent.config as config  # noqa: E402
import httpx  # noqa: E402

# LangGraph 图（import 即建 checkpointer,需要 PG 在线）
from cross_ecommerce_agent.graph.build import graph  # noqa: E402

# WS 关闭码（前端据此跳登录/提示无权限）
WS_CLOSE_UNAUTHORIZED = 4401
WS_CLOSE_FORBIDDEN = 4403
WS_CLOSE_NOT_FOUND = 4404

_INGEST_CLIENT = httpx.Client(timeout=2.0)

# span 上报是 fire-and-forget：固定 4 个线程，避免每条消息都新建线程
_SPAN_EXECUTOR = ThreadPoolExecutor(max_workers=4, thread_name_prefix="span-report")


# ============================================================
# 身份：HTTP 依赖 + WS 校验
# ============================================================
def require_user(request: Request) -> ChatUser:
    """HTTP 接口统一鉴权：cookie -> Laravel /session/me。"""
    user = auth.verify(extract_cookie(request.headers))
    if user is None:
        raise HTTPException(status_code=401, detail="未登录或会话已过期")
    return user


def _user_payload(user: ChatUser) -> dict:
    return {"id": user.id, "name": user.name, "role": user.role}


# ============================================================
# 历史消息: 从 checkpointer 读该 thread 的完整对话(过滤工具消息)
# ============================================================
def load_history(session_id: str) -> list[dict]:
    """返回 [{role: user|assistant, content}, ...]，供会话切换时渲染。

    调用方需先用 manager.require_owned 校验归属。
    """
    thread_id = manager.get_thread_id(session_id)
    if not thread_id:
        return []
    try:
        state = graph.get_state({"configurable": {"thread_id": thread_id}})
    except Exception as exc:                        # noqa: BLE001
        obs.event("history.load_failed", level=logging.WARNING, error=str(exc))
        return []
    out = []
    for m in (state.values.get("messages") or []):
        if isinstance(m, HumanMessage) and m.content:
            out.append({"role": "user", "content": m.content})
        elif isinstance(m, AIMessage) and m.content and not m.tool_calls:
            out.append({"role": "assistant", "content": m.content})
    return out


def delete_checkpoints(thread_id: str) -> None:
    """同步清理 PG checkpointer 历史；供 async 端点用 asyncio.to_thread 调用，避免卡事件循环。"""
    import psycopg
    from cross_ecommerce_agent.config import CHECKPOINT_DB_DSN
    with psycopg.connect(CHECKPOINT_DB_DSN, autocommit=True) as conn:
        for tbl in ("checkpoint_blobs", "checkpoint_writes", "checkpoints"):
            try:
                conn.execute(f"DELETE FROM {tbl} WHERE thread_id = %s", (thread_id,))
            except Exception:
                pass   # 旧版无 blobs 表等,忽略


# ============================================================
# 图执行：同步流 -> asyncio.Queue -> WebSocket
# ============================================================
def run_graph_sync(graph_input, config):
    """同步流式执行图（在线程池里跑）：产出 (mode, data) 帧序列。

    为什么不用 graph.astream：图的 checkpointer 是同步 PostgresSaver，
    异步 API 会抛 NotImplementedError（aget_tuple 未实现）。
    所以用同步 graph.stream + 线程桥接。
    """
    for mode, data in graph.stream(
        graph_input, config, stream_mode=["messages", "values"]
    ):
        yield mode, data


def report_agent_span(*, trace_id: str, user: ChatUser | None, session_id: str | None,
                      status: int, duration_ms: int, intent: str | None = None,
                      tools: list[str] | None = None, error: str | None = None,
                      span: str = "agent.round") -> None:
    """把一轮对话/一条 WS 消息上报给业务系统（内部接口），落 trace_requests。

    为什么不直接写库：Agent 不直连业务库（三层不变量第 1 条）。
    上报失败只影响可观测性，绝不能影响对话，所以丢到后台线程且吞异常。
    """
    if not config.BIZ_API_BASE:
        return

    # id=0 是本地调试占位，不是真实员工，不能写进业务库（tasks.created_by 有外键）
    user_id = user.id if user is not None and user.id not in (None, 0) else None

    payload = {
        "trace_id": trace_id,
        "span": span,
        "status": status,
        "duration_ms": duration_ms,
        "route": (intent or "unknown")[:120],
        "session_id": session_id,
        "user_id": user_id,
        "user_name": user.name if user else None,
        "role": user.role if user else None,
        "meta": {"intent": intent, "tools": tools or [], "error": error},
    }
    url = f"{config.BIZ_API_BASE}/internal/traces"
    # 关键：内部上报必须透传同一条 X-Trace-Id，否则中间件会另生一条孤儿链路
    headers = {
        "Authorization": f"Bearer {config.BIZ_API_TOKEN_AGENT}",
        "X-Trace-Id": trace_id,
    }
    if user_id is not None:
        headers["X-User-Id"] = str(user_id)
    if user is not None:
        if user.name:
            headers["X-User-Name"] = user.name
        if user.role:
            headers["X-User-Role"] = user.role
    if session_id:
        headers["X-Session-Id"] = session_id

    def _send() -> None:
        started = time.perf_counter()
        try:
            resp = _INGEST_CLIENT.post(url, json=payload, headers=headers)
            if resp.status_code >= 400:
                obs.event("agent.span_report_failed", level=logging.WARNING,
                          status=resp.status_code, duration_ms=obs.ms_since(started))
        except Exception as exc:                    # noqa: BLE001
            obs.event("agent.span_report_failed", level=logging.WARNING, error=str(exc))

    _SPAN_EXECUTOR.submit(_send)


async def run_graph_and_stream(websocket, graph_input, config, *,
                               trace_id: str, user: ChatUser, session_id: str):
    """桥接执行：同步图流 -> asyncio.Queue -> WebSocket 帧。

    producer 在后台线程跑同步流式（上下文在**线程内**重新绑定，
    run_in_executor 不复制 contextvars），帧放进队列;
    本协程边收边发,保证打字机效果。
    """
    queue: asyncio.Queue = asyncio.Queue()
    started = time.perf_counter()
    state: dict = {"intent": None, "tools": [], "error": None}

    def producer():
        with obs.bind(trace_id=trace_id, user=user.as_context(), session_id=session_id):
            try:
                for mode, data in run_graph_sync(graph_input, config):
                    queue.put_nowait(("frame", mode, data))
            except Exception as e:                 # 线程里捕获异常,经队列传递
                state["error"] = f"{type(e).__name__}: {e}"
                queue.put_nowait(("error", state["error"], None))
            finally:
                queue.put_nowait(("done", None, None))

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
            obs.event("graph.error", level=logging.ERROR, error=mode)
            obs.metrics.inc("ce_agent_errors_total")
            obs.metrics.inc("ce_chat_messages_total", {"result": "error"})
            await websocket.send_json({"type": "error", "message": f"服务异常: {mode}"})
            report_agent_span(trace_id=trace_id, user=user, session_id=session_id,
                              status=500, duration_ms=obs.ms_since(started),
                              intent=state["intent"], tools=state["tools"], error=mode)
            return
        if mode == "messages":
            msg_chunk, _meta = data
            # 只流 AI 的正文文本；工具调用/工具返回的原始 JSON 不显示
            if isinstance(msg_chunk, AIMessage) and not msg_chunk.tool_calls:
                text = msg_chunk.content
                if text:
                    await websocket.send_json({"type": "token", "content": text})
        elif mode == "values":
            if data.get("intent"):
                state["intent"] = data["intent"]
            for msg in reversed(data.get("messages") or []):
                if isinstance(msg, AIMessage) and msg.tool_calls:
                    state["tools"] = [tc.get("name") for tc in msg.tool_calls if tc.get("name")]
                    break
            if "__interrupt__" in data:        # 图挂起（退款确认等）
                value = data["__interrupt__"][0].value
                await websocket.send_json({"type": "interrupt", "data": value})
                obs.event("graph.interrupt", order_no=str(value.get("order_no", "")))
                obs.metrics.inc("ce_chat_messages_total", {"result": "interrupt"})
                report_agent_span(trace_id=trace_id, user=user, session_id=session_id,
                                  status=200, duration_ms=obs.ms_since(started),
                                  intent=state["intent"], tools=state["tools"])
                return                          # 中断后不再发 message 帧
            if data.get("answer"):             # 记录最新回答（收尾用）
                final_answer = data["answer"]

    # 流正常结束（无中断）
    await websocket.send_json({"type": "message", "content": final_answer, "done": True})
    duration_ms = obs.ms_since(started)
    obs.event("agent.round", duration_ms=duration_ms, intent=state["intent"],
              tools=state["tools"])
    obs.metrics.inc("ce_chat_messages_total", {"result": "ok"})
    obs.metrics.observe("ce_agent_round_seconds", duration_ms / 1000.0)
    report_agent_span(trace_id=trace_id, user=user, session_id=session_id,
                      status=200, duration_ms=duration_ms,
                      intent=state["intent"], tools=state["tools"])


bridge = ChatEventBridge()


@asynccontextmanager
async def lifespan(app: FastAPI):
    """启动/关闭钩子（FastAPI 官方推荐写法,替代已移除的 add_event_handler）。"""
    # 启动: RabbitMQ 消费线程 + 事件循环里的广播任务
    bridge.start(asyncio.get_running_loop())
    pump_task = asyncio.create_task(bridge.pump())
    obs.event("service.start", auth_mode=auth.mode, biz_api=bool(config.BIZ_API_BASE))
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
async def api_list_sessions(user: ChatUser = Depends(require_user)):
    """会话列表(按最后活跃倒序)；普通员工只看自己的。"""
    return {"code": 0, "data": manager.list_sessions(user), "user": _user_payload(user)}


@app.post("/api/sessions")
async def api_create_session(user: ChatUser = Depends(require_user)):
    """新建会话（归属当前登录员工）。"""
    return {"code": 0, "data": manager.create_session(user)}


@app.get("/api/sessions/{session_id}/messages")
async def api_session_messages(session_id: str, user: ChatUser = Depends(require_user)):
    """某会话的历史消息(checkpointer 读取)；越权 403。"""
    try:
        manager.require_owned(session_id, user)
    except KeyError:
        return {"code": 404, "message": "会话不存在", "data": None}
    except NotOwnerError:
        obs.event("session.forbidden", level=logging.WARNING, action="history",
                  session_id=session_id, user_id=user.id)
        return JSONResponse(status_code=403, content={"code": 403, "message": "无权访问该会话", "data": None})
    data = await asyncio.to_thread(load_history, session_id)
    return {"code": 0, "data": data}


@app.delete("/api/sessions/{session_id}")
async def api_delete_session(session_id: str, user: ChatUser = Depends(require_user)):
    """删除会话: sessions.json 记录 + PG checkpointer 历史 + 通知文件。"""
    try:
        manager.require_owned(session_id, user)
    except KeyError:
        return {"code": 404, "message": "会话不存在", "data": None}
    except NotOwnerError:
        obs.event("session.forbidden", level=logging.WARNING, action="delete",
                  session_id=session_id, user_id=user.id)
        return JSONResponse(status_code=403, content={"code": 403, "message": "无权删除该会话", "data": None})

    thread_id = manager.delete_session(session_id)
    if not thread_id:
        return {"code": 404, "message": "会话不存在", "data": None}
    # 清 PG checkpointer(该 thread 的完整对话历史)
    try:
        await asyncio.to_thread(delete_checkpoints, thread_id)
    except Exception as e:
        obs.event("session.delete_failed", level=logging.ERROR, error=str(e))
        return {"code": 500, "message": f"清理会话历史失败: {e}", "data": None}
    # 清审批通知文件(如存在)
    try:
        nf = os.path.join(BASE_DIR, "notifications", f"{thread_id}.md")
        if os.path.exists(nf):
            os.remove(nf)
    except OSError:
        pass
    obs.event("session.deleted", session_id=session_id, by=user.id)
    return {"code": 0, "message": "会话已删除", "data": {"session_id": session_id}}


@app.get("/metrics")
async def metrics_endpoint(request: Request):
    """Prometheus 文本指标（零依赖）。需要 METRICS_TOKEN，避免经 /chat 反代公开暴露。"""
    token = os.getenv("METRICS_TOKEN", "")
    if not token or request.headers.get("authorization") != f"Bearer {token}":
        return PlainTextResponse("forbidden", status_code=403)
    obs.metrics.set_gauge("ce_ws_connections_active", manager.connection_count())
    return PlainTextResponse(obs.metrics.render(), media_type="text/plain; version=0.0.4")


@app.websocket("/ws")
async def ws_chat(websocket: WebSocket) -> None:
    await websocket.accept()

    # 1. 身份：同源 Laravel session cookie；验不过直接关（前端收到 4401 跳登录）
    #    verify 内部是同步 httpx 调用，放线程池里跑，避免卡住事件循环
    cookie = extract_cookie(websocket.headers)
    loop = asyncio.get_running_loop()
    user = await loop.run_in_executor(None, auth.verify, cookie)
    if user is None:
        await websocket.close(code=WS_CLOSE_UNAUTHORIZED, reason="unauthorized")
        return

    # 2. session_id -> thread_id（断线重连、刷新页面后继续同一会话）
    session_id = websocket.query_params.get("session_id", "")
    if session_id:
        try:
            info = manager.require_owned(session_id, user)
        except KeyError:
            await websocket.close(code=WS_CLOSE_NOT_FOUND, reason="session not found")
            return
        except NotOwnerError:
            obs.event("session.forbidden", level=logging.WARNING, action="connect",
                      session_id=session_id, user_id=user.id)
            await websocket.close(code=WS_CLOSE_FORBIDDEN, reason="forbidden")
            return
        thread_id = info["thread_id"]
    else:
        # 容错: 前端正常流程先 POST /api/sessions 再连 WS,这里兜底自动建会话
        info = manager.create_session(user)
        session_id = info["session_id"]
        thread_id = info["thread_id"]

    manager.register(thread_id, websocket)
    obs.metrics.set_gauge("ce_ws_connections_active", manager.connection_count())
    with obs.bind(session_id=session_id, user=user.as_context()):
        obs.event("ws.connect", user_id=user.id, role=user.role)
        await websocket.send_json({"type": "ready", "session_id": session_id,
                                   "user": _user_payload(user)})

        config = {"configurable": {"thread_id": thread_id}}

        try:
            while True:
                raw = await websocket.receive_text()
                msg = json_loads_safe(raw)
                if not msg:
                    continue

                if msg["type"] == "chat":
                    # 每条用户消息一个 trace_id：同一条消息产生的所有 span 都能串起来
                    trace_id = obs.new_trace_id()
                    with obs.bind(trace_id=trace_id):
                        manager.touch(session_id, title=msg["message"])
                        obs.event("chat.message", chars=len(msg["message"]))
                        # 入口 span：与 agent.round / http.request 同 trace，验收 #6 的第三行
                        report_agent_span(
                            trace_id=trace_id, user=user, session_id=session_id,
                            status=200, duration_ms=0, intent="ws.message",
                            span="ws.message",
                        )
                        await run_graph_and_stream(
                            websocket, {"user_input": msg["message"]}, config,
                            trace_id=trace_id, user=user, session_id=session_id,
                        )

                elif msg["type"] == "resume":
                    # ---- 恢复被 interrupt 挂起的图（退款确认等） ----
                    trace_id = obs.new_trace_id()
                    with obs.bind(trace_id=trace_id):
                        obs.event("chat.resume", value=str(msg.get("value")))
                        report_agent_span(
                            trace_id=trace_id, user=user, session_id=session_id,
                            status=200, duration_ms=0, intent="ws.resume",
                            span="ws.message",
                        )
                        await run_graph_and_stream(
                            websocket, Command(resume=msg["value"]), config,
                            trace_id=trace_id, user=user, session_id=session_id,
                        )

        except WebSocketDisconnect:
            pass
        except Exception as exc:                    # noqa: BLE001  连接级异常：记日志，别让进程沉默
            obs.event("ws.error", level=logging.ERROR, error=f"{type(exc).__name__}: {exc}")
        finally:
            manager.unregister(thread_id, websocket)
            obs.metrics.set_gauge("ce_ws_connections_active", manager.connection_count())
            obs.event("ws.disconnect", user_id=user.id)


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
