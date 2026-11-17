# -*- coding: utf-8 -*-
"""聊天服务的身份校验：复用 Laravel 后台 session（同源 cookie），不新建账号体系。

链路：
    浏览器 --(laravel_session cookie)--> nginx /chat/ --> chat:8082
                                                          └--> GET http://php-web/session/me
                                                                └--> {id, name, email, role}

为什么这样做：
- 后台与聊天页同源（8000），cookie 天然可用，不需要第二套登录；
- 业务系统仍然是用户的唯一事实来源，聊天服务只做「验票」；
- 校验结果按 cookie 缓存 30 秒，避免每条消息都回查 Laravel。

安全默认：无法校验（网络失败 / 未配置 BIZ_WEB_BASE）时 **fail closed**，
只有显式 `CHAT_AUTH_MODE=off` 才启用本地调试账号。
"""
from __future__ import annotations

import os
import threading
import time
from dataclasses import dataclass

import httpx

from cross_ecommerce_agent.obs import event as log_event
from cross_ecommerce_agent.obs import log

CACHE_TTL_SECONDS = 30.0
NEGATIVE_TTL_SECONDS = 5.0
MAX_CACHE_ENTRIES = 1000


@dataclass(frozen=True)
class ChatUser:
    """登录员工。id 为 0 表示本地调试账号。"""

    id: int
    name: str
    role: str
    email: str | None = None

    @property
    def is_admin(self) -> bool:
        return self.role == "admin"

    def as_context(self) -> dict:
        """给 obs.bind(user=...) 用，字段与 Laravel 侧 X-User-* 对齐。"""
        return {"id": self.id, "name": self.name, "role": self.role}


DEV_USER = ChatUser(id=0, name="本地调试", role="admin", email=None)


def extract_cookie(headers) -> str | None:
    """从 Starlette 的 headers（大小写不敏感）里取原始 Cookie 头。"""
    try:
        cookie = headers.get("cookie")
    except AttributeError:                     # 普通 dict
        cookie = headers.get("Cookie")
    return cookie or None


class SessionAuth:
    def __init__(self, web_base: str | None = None, mode: str | None = None,
                 client: httpx.Client | None = None) -> None:
        self.mode = (mode if mode is not None else os.getenv("CHAT_AUTH_MODE", "on")).strip().lower()
        self.web_base = (web_base if web_base is not None else os.getenv("BIZ_WEB_BASE", "")).rstrip("/")
        if not self.web_base:
            self.web_base = self._derive_web_base()
        self._client = client or httpx.Client(timeout=3.0)
        self._cache: dict[str, tuple[float, ChatUser | None]] = {}
        self._lock = threading.Lock()

        if self.mode == "off":
            log.warning("CHAT_AUTH_MODE=off：聊天服务不做身份校验，仅限本地调试")
        elif not self.web_base:
            log.error("BIZ_WEB_BASE 未配置，聊天服务将拒绝所有请求（fail closed）")

    # ---------------------------------------------------------------- 对外
    def verify(self, cookie: str | None) -> ChatUser | None:
        """校验 cookie；返回登录用户，未登录/校验失败返回 None。"""
        if self.mode == "off":
            return DEV_USER
        if not cookie:
            return None
        if not self.web_base:
            return None

        now = time.monotonic()
        with self._lock:
            cached = self._cache.get(cookie)
            if cached is not None and cached[0] > now:
                return cached[1]

        user = self._fetch(cookie)

        ttl = CACHE_TTL_SECONDS if user is not None else NEGATIVE_TTL_SECONDS
        with self._lock:
            if len(self._cache) >= MAX_CACHE_ENTRIES:
                # 简单淘汰：丢掉最早过期的一批，够用且不会无限增长
                self._cache = {
                    key: value for key, value in self._cache.items() if value[0] > now
                }
                if len(self._cache) >= MAX_CACHE_ENTRIES:
                    self._cache.clear()
            self._cache[cookie] = (now + ttl, user)

        return user

    # ---------------------------------------------------------------- 内部
    def _fetch(self, cookie: str) -> ChatUser | None:
        url = f"{self.web_base}/session/me"
        try:
            resp = self._client.get(url, headers={"Cookie": cookie, "Accept": "application/json"})
        except httpx.HTTPError as exc:
            # fail closed：验不了票就不放行，但要留下告警线索
            log_event("auth.verify_failed", error=str(exc), url=url)
            return None

        if resp.status_code != 200:
            return None

        try:
            payload = resp.json()
        except ValueError:
            log_event("auth.bad_response", status=resp.status_code, url=url)
            return None

        data = payload.get("data") or {}
        if not data.get("id"):
            return None

        return ChatUser(
            id=int(data["id"]),
            name=str(data.get("name") or "未命名"),
            role=str(data.get("role") or "agent"),
            email=data.get("email"),
        )

    @staticmethod
    def _derive_web_base() -> str:
        """BIZ_API_BASE=http://php-web/api/v1 -> http://php-web"""
        base = os.getenv("BIZ_API_BASE", "").rstrip("/")
        suffix = "/api/v1"
        if base.endswith(suffix):
            return base[: -len(suffix)]
        return base


auth = SessionAuth()
