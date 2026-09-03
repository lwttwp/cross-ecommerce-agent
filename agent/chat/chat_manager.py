# -*- coding: utf-8 -*-
"""
会话管理：session_id <-> thread_id 映射（持久化 JSON）+ WebSocket 连接注册表。

数据文件: agent/chat/chat_sessions.json
  {
    "sessions": {
      "s-xxxx": {"thread_id": "chat-xxxx", "title": "退款咨询",
                 "created_at": "...", "last_active": "..."}
    }
  }

职责:
1. 会话 CRUD: create_session / list_sessions / get_thread_id / touch
2. WebSocket 注册表: thread_id -> set[WebSocket]，供 notify_bridge 推送审批事件
3. 连接关闭时清理注册表

用法（chat_server.py）:
    from chat.chat_manager import manager
    info = manager.create_session()          # 新建会话
    thread_id = manager.get_thread_id(sid)   # 按 session_id 取 thread_id
    manager.touch(sid, title="...")          # 更新活跃时间/自动命名
    manager.register(thread_id, websocket)   # 连接建立时
    manager.unregister(thread_id, websocket) # 连接关闭时
    await manager.broadcast(thread_id, payload)  # 推给该会话所有连接
"""
import json
import os
import threading
import uuid
from datetime import datetime, timezone

SESSIONS_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "chat_sessions.json")

_LOCK = threading.Lock()
# thread_id -> set[WebSocket]；WebSocket 对象来自 fastapi.websockets
_CONNECTIONS: dict[str, set] = {}


class ChatManager:
    def __init__(self):
        self._sessions: dict[str, dict] = {}
        self._load()

    # ---------------- 持久化 ----------------
    def _load(self) -> None:
        try:
            with open(SESSIONS_FILE, encoding="utf-8") as f:
                self._sessions = json.load(f).get("sessions", {})
        except (FileNotFoundError, json.JSONDecodeError):
            self._sessions = {}

    def _save(self) -> None:
        tmp = SESSIONS_FILE + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump({"sessions": self._sessions}, f, ensure_ascii=False, indent=2)
        os.replace(tmp, SESSIONS_FILE)   # 原子替换,避免写一半损坏

    # ---------------- 会话 CRUD ----------------
    def create_session(self, title: str = "新会话") -> dict:
        """新建会话,返回 {session_id, thread_id, title, created_at, last_active}。"""
        with _LOCK:
            session_id = "s-" + uuid.uuid4().hex[:12]
            thread_id = "chat-" + uuid.uuid4().hex[:16]
            now = datetime.now(timezone.utc).isoformat(timespec="seconds")
            info = {"thread_id": thread_id, "title": title,
                    "created_at": now, "last_active": now}
            self._sessions[session_id] = info
            self._save()
            return {"session_id": session_id, **info}

    def list_sessions(self) -> list[dict]:
        """全部会话,按最后活跃时间倒序。"""
        with _LOCK:
            items = [{"session_id": sid, **info} for sid, info in self._sessions.items()]
        return sorted(items, key=lambda x: x.get("last_active", ""), reverse=True)

    def get_thread_id(self, session_id: str) -> str | None:
        with _LOCK:
            info = self._sessions.get(session_id)
            return info["thread_id"] if info else None

    def delete_session(self, session_id: str) -> str | None:
        """删除会话记录,返回 thread_id(调用方据此清理 checkpointer 历史)。"""
        with _LOCK:
            info = self._sessions.pop(session_id, None)
            if info:
                self._save()
            return info["thread_id"] if info else None

    def touch(self, session_id: str, title: str | None = None) -> None:
        """更新最后活跃时间;标题还是默认值时用首条消息自动命名。"""
        with _LOCK:
            info = self._sessions.get(session_id)
            if not info:
                return
            info["last_active"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
            if title and info.get("title") in (None, "新会话"):
                info["title"] = title[:12]
            self._save()

    # ---------------- WebSocket 注册表 ----------------
    def register(self, thread_id: str, ws) -> None:
        with _LOCK:
            _CONNECTIONS.setdefault(thread_id, set()).add(ws)

    def unregister(self, thread_id: str, ws) -> None:
        with _LOCK:
            conns = _CONNECTIONS.get(thread_id)
            if conns:
                conns.discard(ws)
                if not conns:
                    _CONNECTIONS.pop(thread_id, None)

    async def broadcast(self, thread_id: str, payload: dict) -> int:
        """给该会话的所有在线连接推送 JSON 帧,返回成功推送数。"""
        import json as _json
        with _LOCK:
            conns = list(_CONNECTIONS.get(thread_id, ()))
        if not conns:
            return 0
        sent = 0
        dead = []
        for ws in conns:
            try:
                await ws.send_text(_json.dumps(payload, ensure_ascii=False))
                sent += 1
            except Exception:                    # 连接已断开等
                dead.append(ws)
        if dead:                                 # 清理失效连接
            with _LOCK:
                conns_alive = _CONNECTIONS.get(thread_id)
                if conns_alive:
                    for ws in dead:
                        conns_alive.discard(ws)
        return sent


manager = ChatManager()
