# -*- coding: utf-8 -*-
"""身份校验单测：Laravel session 校验、缓存、fail closed。"""
import os
import sys
import unittest

import httpx

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, BASE_DIR)
sys.path.insert(0, os.path.join(BASE_DIR, "src"))

from chat.auth import DEV_USER, SessionAuth, extract_cookie  # noqa: E402


class FakeResponse:
    def __init__(self, status_code: int, payload=None):
        self.status_code = status_code
        self._payload = payload

    def json(self):
        if self._payload is None:
            raise ValueError("not json")
        return self._payload


class FakeClient:
    """记录调用次数，按脚本依次返回预设响应。"""

    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = 0
        self.last_headers = None

    def get(self, url, headers=None):
        self.calls += 1
        self.last_headers = headers
        item = self.responses.pop(0) if len(self.responses) > 1 else self.responses[0]
        if isinstance(item, Exception):
            raise item
        return item


def ok_payload(uid=7, name="客服A", role="agent"):
    return {"code": 0, "data": {"id": uid, "name": name, "email": "a@example.com", "role": role}}


class SessionAuthTest(unittest.TestCase):
    def test_mode_off_returns_dev_user_without_http(self):
        client = FakeClient([FakeResponse(200, ok_payload())])
        auth = SessionAuth(web_base="http://php-web", mode="off", client=client)
        self.assertEqual(auth.verify(None), DEV_USER)
        self.assertEqual(client.calls, 0)

    def test_verified_user(self):
        client = FakeClient([FakeResponse(200, ok_payload())])
        auth = SessionAuth(web_base="http://php-web", mode="on", client=client)
        user = auth.verify("laravel_session=abc")
        self.assertIsNotNone(user)
        self.assertEqual(user.id, 7)
        self.assertEqual(user.name, "客服A")
        self.assertFalse(user.is_admin)
        self.assertEqual(client.last_headers["Cookie"], "laravel_session=abc")

    def test_caches_positive_result(self):
        client = FakeClient([FakeResponse(200, ok_payload())])
        auth = SessionAuth(web_base="http://php-web", mode="on", client=client)
        auth.verify("c=1")
        auth.verify("c=1")
        self.assertEqual(client.calls, 1)

    def test_unauthenticated_is_none(self):
        client = FakeClient([FakeResponse(401, {"code": 401})])
        auth = SessionAuth(web_base="http://php-web", mode="on", client=client)
        self.assertIsNone(auth.verify("c=1"))

    def test_network_error_fails_closed(self):
        client = FakeClient([httpx.ConnectError("boom")])
        auth = SessionAuth(web_base="http://php-web", mode="on", client=client)
        self.assertIsNone(auth.verify("c=1"))

    def test_no_cookie_no_call(self):
        client = FakeClient([FakeResponse(200, ok_payload())])
        auth = SessionAuth(web_base="http://php-web", mode="on", client=client)
        self.assertIsNone(auth.verify(None))
        self.assertEqual(client.calls, 0)

    def test_missing_web_base_denies(self):
        client = FakeClient([FakeResponse(200, ok_payload())])
        auth = SessionAuth(web_base="", mode="on", client=client)
        auth.web_base = ""
        self.assertIsNone(auth.verify("c=1"))

    def test_derive_web_base_from_api_base(self):
        old = os.environ.get("BIZ_API_BASE")
        os.environ["BIZ_API_BASE"] = "http://php-web/api/v1"
        try:
            self.assertEqual(SessionAuth._derive_web_base(), "http://php-web")
        finally:
            if old is None:
                os.environ.pop("BIZ_API_BASE", None)
            else:
                os.environ["BIZ_API_BASE"] = old


class CookieHeaderTest(unittest.TestCase):
    def test_extract_cookie(self):
        self.assertEqual(extract_cookie({"cookie": "a=b"}), "a=b")

        class Headers:
            def get(self, key):
                return "x=y" if key == "cookie" else None

        self.assertEqual(extract_cookie(Headers()), "x=y")
        self.assertIsNone(extract_cookie({}))


if __name__ == "__main__":
    unittest.main()
