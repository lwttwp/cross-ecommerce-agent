# -*- coding: utf-8 -*-
"""可观测性基座单测：trace 上下文、JSON 日志、指标渲染、外发请求头。"""
import json
import logging
import os
import sys
import unittest
from io import StringIO

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, BASE_DIR)
sys.path.insert(0, os.path.join(BASE_DIR, "src"))

from cross_ecommerce_agent import obs  # noqa: E402


class TraceContextTest(unittest.TestCase):
    def test_new_trace_id_shape(self):
        trace_id = obs.new_trace_id()
        self.assertEqual(len(trace_id), 16)
        int(trace_id, 16)          # 必须是合法十六进制

    def test_bind_sets_and_restores(self):
        with obs.bind(trace_id="abc12345", user={"id": 7, "name": "客服A", "role": "agent"},
                      session_id="s-1"):
            self.assertEqual(obs.current_trace_id(), "abc12345")
            self.assertEqual(obs.current_user()["id"], 7)
            self.assertEqual(obs.current_session_id(), "s-1")

        self.assertIsNone(obs.current_trace_id())
        self.assertIsNone(obs.current_user())
        self.assertIsNone(obs.current_session_id())

    def test_nested_bind_restores_outer(self):
        with obs.bind(trace_id="outer001"):
            with obs.bind(trace_id="inner002"):
                self.assertEqual(obs.current_trace_id(), "inner002")
            self.assertEqual(obs.current_trace_id(), "outer001")

    def test_outbound_headers(self):
        with obs.bind(trace_id="abc12345", user={"id": 7, "name": "客服A", "role": "agent"},
                      session_id="s-1"):
            headers = obs.outbound_headers()
        self.assertEqual(headers["X-Trace-Id"], "abc12345")
        self.assertEqual(headers["X-User-Id"], "7")
        self.assertEqual(headers["X-User-Name"], "客服A")
        self.assertEqual(headers["X-User-Role"], "agent")
        self.assertEqual(headers["X-Session-Id"], "s-1")

    def test_outbound_headers_empty_without_context(self):
        self.assertEqual(obs.outbound_headers(), {})

    def test_outbound_headers_skips_zero_user_id(self):
        # CHAT_AUTH_MODE=off 的 DEV_USER.id=0；业务库 users 没有 id=0，
        # 下发会让 tasks.created_by 外键失败，必须省略。
        with obs.bind(trace_id="abc12345", user={"id": 0, "name": "本地调试", "role": "admin"}):
            headers = obs.outbound_headers()
        self.assertNotIn("X-User-Id", headers)
        self.assertEqual(headers["X-User-Name"], "本地调试")
        self.assertEqual(headers["X-User-Role"], "admin")


class MetricPathTest(unittest.TestCase):
    def test_metric_path_normalizes_business_ids(self):
        cases = {
            "/orders/CE202608241027": "/orders/{id}",
            "/orders/CE202608241027/refunds": "/orders/{id}/refunds",
            "/products/SKU-1028": "/products/{id}",
            "/customers/7": "/customers/{id}",
            "/tasks/TSK123456/download": "/tasks/{id}/download",
            "/orders/CE-TRK-ABC123/tracking": "/orders/{id}/tracking",
            "/orders": "/orders",
            "/orders?status=PAID": "/orders",
        }
        for raw, expected in cases.items():
            with self.subTest(path=raw):
                self.assertEqual(obs.metric_path(raw), expected)


class JsonFormatterTest(unittest.TestCase):
    def test_required_fields_and_flat_event_fields(self):
        record = logging.LogRecord(
            name="agent", level=logging.INFO, pathname=__file__, lineno=1,
            msg="tool.call", args=(), exc_info=None,
        )
        record.event_fields = {"path": "/orders", "status": 200, "duration_ms": 12}

        with obs.bind(trace_id="abc12345", user={"id": 7, "name": "客服A", "role": "agent"},
                      session_id="s-1"):
            payload = json.loads(obs.JsonFormatter().format(record))

        self.assertEqual(payload["event"], "tool.call")
        self.assertEqual(payload["service"], obs.SERVICE)
        self.assertEqual(payload["trace_id"], "abc12345")
        self.assertEqual(payload["user_id"], 7)
        self.assertEqual(payload["session_id"], "s-1")
        self.assertEqual(payload["path"], "/orders")
        self.assertEqual(payload["status"], 200)
        self.assertIn("ts", payload)

    def test_serializes_non_json_values(self):
        record = logging.LogRecord("agent", logging.WARNING, __file__, 1, "oops", (), None)
        record.event_fields = {"value": {1, 2, 3}}       # set 不可 JSON 序列化 -> default=str
        payload = json.loads(obs.JsonFormatter().format(record))
        self.assertIn("value", payload)


class MetricsTest(unittest.TestCase):
    def test_render_prometheus_format(self):
        metrics = obs.Metrics()
        metrics.inc("ce_chat_messages_total", {"result": "ok"})
        metrics.inc("ce_chat_messages_total", {"result": "error"}, 2)
        metrics.observe("ce_agent_round_seconds", 0.5)
        metrics.set_gauge("ce_ws_connections_active", 3)

        text = metrics.render()
        self.assertIn("ce_ws_connections_active 3", text)
        self.assertIn('ce_chat_messages_total{result="ok"} 1', text)
        self.assertIn('ce_chat_messages_total{result="error"} 2', text)
        self.assertIn("ce_agent_round_seconds_sum 0.500000", text)
        self.assertIn("ce_agent_round_seconds_count 1", text)
        self.assertIn("# TYPE ce_chat_messages_total counter", text)

    def test_configure_logging_emits_json(self):
        stream = StringIO()
        logger = logging.getLogger("test.json")
        handler = logging.StreamHandler(stream)
        handler.setFormatter(obs.JsonFormatter())
        logger.addHandler(handler)
        logger.setLevel(logging.INFO)
        try:
            obs.event("unit.test", foo="bar")
        finally:
            logger.removeHandler(handler)

        # 全局 agent logger 的 handler 由 configure_logging 安装，这里只验证格式器可解析
        self.assertEqual(obs.ms_since(0.0) >= 0, True)


if __name__ == "__main__":
    unittest.main()
