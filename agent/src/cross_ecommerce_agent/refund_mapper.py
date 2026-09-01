# -*- coding: utf-8 -*-
"""
refund_no -> thread_id 映射(方案 B:审批事件回定位会话)。

Agent 提交退款时记录映射;refund_consumer 收到审批事件后查映射,
将通知精确投递到发起退款的会话。

存储:JSON 文件(演示形态,原子写 + 进程内锁)。
生产建议:Redis Hash 或 DB 表,TTL 可设(如 7 天)防止无限增长。
"""
import json
import os
import threading

MAP_PATH = os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    "refund_thread_map.json",
)

_lock = threading.Lock()


def record(refund_no: str, thread_id: str) -> None:
    """记录退款单所属会话。"""
    with _lock:
        data = _load()
        data[refund_no] = thread_id
        _save(data)


def lookup(refund_no: str) -> str | None:
    """按退款单号查会话;不存在返回 None。"""
    return _load().get(refund_no)


def _load() -> dict:
    if not os.path.exists(MAP_PATH):
        return {}
    try:
        with open(MAP_PATH, encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except (json.JSONDecodeError, OSError):
        return {}


def _save(data: dict) -> None:
    tmp = MAP_PATH + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.replace(tmp, MAP_PATH)  # 原子替换,避免半写文件
