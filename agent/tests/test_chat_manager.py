# -*- coding: utf-8 -*-
"""会话归属（多用户隔离）单测：跑 `python -m unittest discover -s tests`。

覆盖：
- 新建会话写入 owner；
- 列表按角色过滤（员工只看自己，管理员看全部）；
- 越权访问 history/delete/WS 的前置校验（require_owned）；
- v1 老数据迁移为 v2（owner_id=0，仅管理员可见）。
"""
import json
import os
import sys
import tempfile
import unittest

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, BASE_DIR)
sys.path.insert(0, os.path.join(BASE_DIR, "src"))

from chat.chat_manager import ChatManager, LEGACY_OWNER_ID, NotOwnerError  # noqa: E402


class FakeUser:
    def __init__(self, uid: int, role: str = "agent", name: str = "员工"):
        self.id = uid
        self.role = role
        self.name = name

    @property
    def is_admin(self) -> bool:
        return self.role == "admin"


class ChatManagerOwnershipTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.path = os.path.join(self._tmp.name, "sessions.json")
        self.manager = ChatManager(path=self.path)
        self.alice = FakeUser(1, "agent", "客服A")
        self.bob = FakeUser(2, "agent", "客服B")
        self.admin = FakeUser(99, "admin", "管理员")

    def tearDown(self):
        self._tmp.cleanup()

    def test_create_session_records_owner(self):
        info = self.manager.create_session(self.alice)
        self.assertEqual(info["owner_id"], 1)
        self.assertEqual(info["owner_name"], "客服A")
        self.assertTrue(info["session_id"].startswith("s-"))
        self.assertTrue(info["thread_id"].startswith("chat-"))

    def test_list_sessions_only_own_for_agent(self):
        a = self.manager.create_session(self.alice)
        b = self.manager.create_session(self.bob)

        alice_ids = {s["session_id"] for s in self.manager.list_sessions(self.alice)}
        self.assertEqual(alice_ids, {a["session_id"]})

        admin_ids = {s["session_id"] for s in self.manager.list_sessions(self.admin)}
        self.assertEqual(admin_ids, {a["session_id"], b["session_id"]})

    def test_require_owned(self):
        info = self.manager.create_session(self.alice)

        self.assertEqual(self.manager.require_owned(info["session_id"], self.alice)["thread_id"],
                         info["thread_id"])

        with self.assertRaises(NotOwnerError):
            self.manager.require_owned(info["session_id"], self.bob)

        with self.assertRaises(KeyError):
            self.manager.require_owned("s-not-exist", self.alice)

        # 管理员可以接管任何会话（排障）
        self.assertEqual(self.manager.require_owned(info["session_id"], self.admin)["thread_id"],
                         info["thread_id"])

    def test_delete_returns_thread_id(self):
        info = self.manager.create_session(self.alice)
        thread_id = self.manager.delete_session(info["session_id"])
        self.assertEqual(thread_id, info["thread_id"])
        self.assertIsNone(self.manager.get(info["session_id"]))

    def test_legacy_v1_is_migrated(self):
        legacy = {
            "sessions": {
                "s-old": {"thread_id": "chat-old", "title": "旧会话",
                          "created_at": "2026-08-01T00:00:00+00:00",
                          "last_active": "2026-08-01T00:00:00+00:00"},
            }
        }
        with open(self.path, "w", encoding="utf-8") as f:
            json.dump(legacy, f, ensure_ascii=False)

        manager = ChatManager(path=self.path)
        info = manager.get("s-old")
        self.assertEqual(info["owner_id"], LEGACY_OWNER_ID)
        self.assertEqual(info["owner_name"], "(未归属)")

        # 老会话对员工不可见，对管理员可见（不丢历史）
        self.assertEqual(manager.list_sessions(self.alice), [])
        self.assertEqual(len(manager.list_sessions(self.admin)), 1)

        # 落盘结构已升级为 v2
        with open(self.path, encoding="utf-8") as f:
            persisted = json.load(f)
        self.assertEqual(persisted["version"], 2)
        self.assertIn("owner_id", persisted["sessions"]["s-old"])

    def test_touch_updates_title_and_activity(self):
        info = self.manager.create_session(self.alice)
        title = "查订单 CE202608241024"
        self.manager.touch(info["session_id"], title=title)
        updated = self.manager.get(info["session_id"])
        self.assertEqual(updated["title"], title[:12])   # 自动命名截断 12 字
        self.assertGreaterEqual(updated["last_active"], info["last_active"])


if __name__ == "__main__":
    unittest.main()
