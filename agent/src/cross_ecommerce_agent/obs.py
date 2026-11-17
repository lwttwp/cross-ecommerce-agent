# -*- coding: utf-8 -*-
"""可观测性基座：trace 上下文、结构化 JSON 日志、极简指标、外发请求头。

设计要点（与 docs/OBSERVABILITY_AND_ISOLATION.md 对应）：
1. `trace_id` 在入口（WebSocket 收到一条消息）生成，之后只透传不改写；
   跨线程（graph 跑在线程池）用 contextvars 显式绑定，不依赖线程继承；
2. 日志一行一个 JSON，必备字段 ts/level/service/event，可选 trace_id/user_id/session_id；
3. 指标零依赖，直接吐 Prometheus 文本格式，后续接 scrape 不用改代码。
"""
from __future__ import annotations

import json
import logging
import os
import re
import sys
import threading
import time
import uuid
from contextlib import contextmanager
from contextvars import ContextVar
from datetime import datetime, timezone
from typing import Any, Iterator

SERVICE = os.getenv("OBS_SERVICE_NAME", "agent")

# 全局日志级别：INFO 默认；排障时 OBS_DEBUG=1 打开 DEBUG
_LOG_LEVEL = logging.DEBUG if os.getenv("OBS_DEBUG") == "1" else logging.INFO

_trace_id: ContextVar[str | None] = ContextVar("trace_id", default=None)
_user: ContextVar[dict | None] = ContextVar("user", default=None)
_session_id: ContextVar[str | None] = ContextVar("session_id", default=None)


# ---------------------------------------------------------------- trace 上下文
def new_trace_id() -> str:
    """16 位十六进制，和 Laravel 侧 TraceContext::generate() 保持一致的长度。"""
    return uuid.uuid4().hex[:16]


def current_trace_id() -> str | None:
    return _trace_id.get()


def current_user() -> dict | None:
    return _user.get()


def current_session_id() -> str | None:
    return _session_id.get()


@contextmanager
def bind(*, trace_id: str | None = None, user: dict | None = None,
         session_id: str | None = None) -> Iterator[None]:
    """在 with 块内绑定上下文；退出后自动还原（幂等，可嵌套）。"""
    tokens = []
    if trace_id is not None:
        tokens.append((_trace_id, _trace_id.set(trace_id)))
    if user is not None:
        tokens.append((_user, _user.set(user)))
    if session_id is not None:
        tokens.append((_session_id, _session_id.set(session_id)))
    try:
        yield
    finally:
        for var, token in reversed(tokens):
            var.reset(token)


def outbound_headers() -> dict[str, str]:
    """给业务系统 / 上报接口用的链路与身份头（缺省时不下发空值）。"""
    headers: dict[str, str] = {}
    trace_id = current_trace_id()
    if trace_id:
        headers["X-Trace-Id"] = trace_id
    user = current_user()
    if user:
        # id=0 是本地调试占位，业务库 users 表没有 id=0，不能下发成外键值。
        uid = user.get("id")
        if isinstance(uid, int) and uid > 0:
            headers["X-User-Id"] = str(uid)
        if user.get("name"):
            headers["X-User-Name"] = str(user["name"])
        if user.get("role"):
            headers["X-User-Role"] = str(user["role"])
    session_id = current_session_id()
    if session_id:
        headers["X-Session-Id"] = session_id
    return headers


_BUSINESS_ID_RE = re.compile(
    r"/(CE\d{12}|RF\d{12}|CE-TRK-[A-Za-z0-9]+|SKU-\d+|TSK\d+|\d+)(?=/|$)"
)


def metric_path(path: str) -> str:
    """把 path 里的业务单号/SKU/ID 归一为 {id}，避免 Prometheus label 基数爆炸。

    例：/orders/CE202608241027 → /orders/{id}
        /tasks/TSK123456/download → /tasks/{id}/download
    """
    return _BUSINESS_ID_RE.sub("/{id}", (path or "").split("?", 1)[0])


# ---------------------------------------------------------------- JSON 日志
class JsonFormatter(logging.Formatter):
    """单行 JSON；`extra={"event_fields": {...}}` 里的键会平铺到顶层。"""

    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, Any] = {
            "ts": datetime.fromtimestamp(record.created, tz=timezone.utc)
            .isoformat(timespec="milliseconds")
            .replace("+00:00", "Z"),
            "level": record.levelname,
            "service": SERVICE,
            "logger": record.name,
            "event": record.getMessage(),
        }

        trace_id = current_trace_id()
        if trace_id:
            payload["trace_id"] = trace_id
        session_id = current_session_id()
        if session_id:
            payload["session_id"] = session_id
        user = current_user()
        if user and user.get("id") is not None:
            payload["user_id"] = user["id"]
        if user and user.get("role"):
            payload["role"] = user["role"]

        fields = getattr(record, "event_fields", None)
        if isinstance(fields, dict):
            for key, value in fields.items():
                if key not in payload:
                    payload[key] = value

        if record.exc_info:
            payload["error"] = self.formatException(record.exc_info)

        return json.dumps(payload, ensure_ascii=False, default=str)


def configure_logging(log_dir: str) -> logging.Logger:
    """把根 logger 换成 JSON 输出（stdout + 文件），并收敛第三方噪音。

    重复调用安全（先清空已有 handler）。
    """
    root = logging.getLogger()
    for handler in list(root.handlers):
        root.removeHandler(handler)

    formatter = JsonFormatter()

    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(formatter)

    handlers: list[logging.Handler] = [stream]
    try:
        os.makedirs(log_dir, exist_ok=True)
        file_handler = logging.FileHandler(os.path.join(log_dir, "agent.log"), encoding="utf-8")
        file_handler.setFormatter(formatter)
        handlers.append(file_handler)
    except OSError:
        pass  # 只读文件系统等场景降级为仅 stdout

    for handler in handlers:
        root.addHandler(handler)
    root.setLevel(_LOG_LEVEL)

    # uvicorn / httpx 的访问日志也用同一格式，避免两套日志格式混在一起
    for name in ("uvicorn", "uvicorn.access", "uvicorn.error", "httpx", "httpcore", "pika"):
        logging.getLogger(name).setLevel(max(_LOG_LEVEL, logging.WARNING))

    return logging.getLogger("agent")


log = logging.getLogger("agent")


def event(name: str, level: int = logging.INFO, **fields: Any) -> None:
    """结构化事件日志：event("tool.call", path=..., status=200, duration_ms=12)。"""
    logging.getLogger("agent").log(level, name, extra={"event_fields": fields})


# ---------------------------------------------------------------- 指标
def _labels(labels: dict[str, str] | None) -> str:
    if not labels:
        return ""
    inner = ",".join(f'{k}="{_escape(v)}"' for k, v in sorted(labels.items()))
    return "{" + inner + "}"


def _escape(value: str) -> str:
    return str(value).replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


class Metrics:
    """最小实现：计数器 + 累计值/次数（够算平均与 QPS），线程安全。"""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._counters: dict[tuple[str, str], float] = {}
        self._sums: dict[tuple[str, str], float] = {}
        self._counts: dict[tuple[str, str], int] = {}
        self._gauges: dict[str, float] = {}

    def inc(self, name: str, labels: dict[str, str] | None = None, value: float = 1.0) -> None:
        key = (name, _labels(labels))
        with self._lock:
            self._counters[key] = self._counters.get(key, 0.0) + value

    def observe(self, name: str, seconds: float, labels: dict[str, str] | None = None) -> None:
        key = (name, _labels(labels))
        with self._lock:
            self._sums[key] = self._sums.get(key, 0.0) + seconds
            self._counts[key] = self._counts.get(key, 0) + 1

    def set_gauge(self, name: str, value: float) -> None:
        with self._lock:
            self._gauges[name] = value

    def render(self) -> str:
        lines: list[str] = []
        with self._lock:
            gauges = dict(self._gauges)
            counters = dict(self._counters)
            sums = dict(self._sums)
            counts = dict(self._counts)

        for name, value in sorted(gauges.items()):
            lines.append(f"# TYPE {name} gauge")
            lines.append(f"{name} {value:g}")

        for (name, label_text), value in sorted(counters.items()):
            lines.append(f"# TYPE {name} counter")
            lines.append(f"{name}{label_text} {value:g}")

        # 直方图用 sum/count 表示（平均延迟 = sum / count）
        for (name, label_text), total in sorted(sums.items()):
            count = counts.get((name, label_text), 0)
            lines.append(f"# TYPE {name} summary")
            lines.append(f"{name}_sum{label_text} {total:.6f}")
            lines.append(f"{name}_count{label_text} {count}")

        return "\n".join(lines) + "\n"


metrics = Metrics()


def ms_since(started_at: float) -> int:
    """perf_counter 起点 -> 毫秒整数（日志与上报统一用 ms）。"""
    return int(round((time.perf_counter() - started_at) * 1000))
