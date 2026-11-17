# -*- coding: utf-8 -*-
"""会话管理：session_id <-> thread_id 映射（持久化 JSON）+ WebSocket 连接注册表。

数据文件: agent/chat/chat_sessions.json（v2，带归属）
  {
    "version": 2,
    "sessions": {
      "s-xxxx": {"thread_id": "chat-xxxx", "title": "退款咨询",
                 "owner_id": 7, "owner_name": "客服小王",
                 "created_at": "...", "last_active": "..."}
    }
  }

多用户规则（与 docs/OBSERVABILITY_AND_ISOLATION.md 一致）：
- 新建会话归属当前登录员工；
- 列表默认只返回自己的；管理员可见全部（排障/接管）；
- 历史、删除、WebSocket 连接都校验归属，越权抛 NotOwnerError；
- v1 老数据（无 owner）归到 owner_id=0"未归属"，仅管理员可见，不丢历史。

职责:
1. 会话 CRUD + 归属校验
2. WebSocket 注册表: thread_id -> set[WebSocket]，供 notify_bridge 推送审批事件
"""
from __future__ import annotations

import json
import os
import threading
import uuid
from datetime import datetime, timezone

SESSIONS_FILE = os.getenv(
    "CHAT_SESSIONS_FILE",
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "chat_sessions.json"),
)

LEGACY_OWNER_ID = 0
LEGACY_OWNER_NAME = "(未归属)"

_LOCK = threading.Lock()
# thread_id -> set[WebSocket]；WebSocket 对象来自 fastapi.websockets
_CONNECTIONS: dict[str, set] = {}


class NotOwnerError(Exception):
    """会话存在但不属于当前用户（与内置 PermissionError 区分，便于精确捕获）。"""


class ChatManager:
    def __init__(self, path: str = SESSIONS_FILE):
        self._path = path
        self._sessions: dict[str, dict] = {}
        self._needs_rewrite = False
        self._load()
        if self._needs_rewrite:
            try:
                self._save()          # 老数据立刻升级为 v2
            except OSError:
                pass                  # 只读环境下降级：内存里已是新结构

    # ---------------- 持久化 ----------------
    def _load(self) -> None:
        try:
            with open(self._path, encoding="utf-8") as f:
                data = json.load(f)
        except (FileNotFoundError, json.JSONDecodeError):
            self._sessions = {}
            return

        sessions = data.get("sessions", {})
        migrated = False
        for info in sessions.values():
            if "owner_id" not in info:
                info["owner_id"] = LEGACY_OWNER_ID
                info["owner_name"] = LEGACY_OWNER_NAME
                migrated = True
        self._sessions = sessions
        # v1 -> v2：下次写入时顺带落盘新结构
        self._needs_rewrite = migrated or data.get("version") != 2

    def _save(self) -> None:
        tmp = self._path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump({"version": 2, "sessions": self._sessions}, f,
                      ensure_ascii=False, indent=2)
        os.replace(tmp, self._path)   # 原子替换,避免写一半损坏
        self._needs_rewrite = False

    def _maybe_rewrite(self) -> None:
        """（保留给运维脚本）需要时把内存结构强制落盘为 v2。"""
        if self._needs_rewrite:
            self._save()

    # ---------------- 会话 CRUD ----------------
    def create_session(self, user=None, title: str = "新会话") -> dict:
        """新建会话,返回 {session_id, thread_id, title, owner_*, created_at, last_active}。"""
        owner_id = getattr(user, "id", None) if user is not None else None
        owner_name = getattr(user, "name", None) if user is not None else None
        with _LOCK:
            session_id = "s-" + uuid.uuid4().hex[:12]
            thread_id = "chat-" + uuid.uuid4().hex[:16]
            now = datetime.now(timezone.utc).isoformat(timespec="seconds")
            info = {"thread_id": thread_id, "title": title,
                    "owner_id": owner_id if owner_id is not None else LEGACY_OWNER_ID,
                    "owner_name": owner_name or LEGACY_OWNER_NAME,
                    "created_at": now, "last_active": now}
            self._sessions[session_id] = info
            self._save()
            return {"session_id": session_id, **info}

    def list_sessions(self, user=None) -> list[dict]:
        """会话列表（按最后活跃倒序）；管理员看全部，其他人只看自己。"""
        include_all = bool(getattr(user, "is_admin", False)) or user is None
        owner_id = getattr(user, "id", None)
        with _LOCK:
            items = [{"session_id": sid, **info} for sid, info in self._sessions.items()]
        if not include_all:
            items = [it for it in items if it.get("owner_id") == owner_id]
        return sorted(items, key=lambda x: x.get("last_active", ""), reverse=True)

    def get(self, session_id: str) -> dict | None:
        with _LOCK:
            info = self._sessions.get(session_id)
            return dict(info) if info else None

    def require_owned(self, session_id: str, user) -> dict:
        """取会话并校验归属：不存在抛 KeyError，不属于当前用户抛 NotOwnerError。"""
        info = self.get(session_id)
        if info is None:
            raise KeyError(session_id)
        if getattr(user, "is_admin", False):
            return info
        if info.get("owner_id") != getattr(user, "id", None):
            raise NotOwnerError(session_id)
        return info

    def get_thread_id(self, session_id: str) -> str | None:
        """内部用：调用方应已用 require_owned 校验过归属。"""
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

    def connection_count(self) -> int:
        """在线连接数（指标用）。"""
        with _LOCK:
            return sum(len(conns) for conns in _CONNECTIONS.values())

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
