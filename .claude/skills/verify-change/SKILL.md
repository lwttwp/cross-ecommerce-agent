---
name: verify-change
description: 改动本仓库代码后，用正确的验证阶梯回归（Python 单测 → MCP 冒烟 → stdio 端到端 → LangGraph 链路 → 34 条 eval）。只要改了 business-system/、agent/、mcp-server/ 或 eval/ 下任何文件，就应当用这个 skill 决定跑哪几层——避免漏跑导致回归，也避免每次都全量跑浪费时间。用户说"验证一下""跑测试""改完确认没坏""回归""跑一下 eval"时也用这个。
---

# 改动后验证

本仓库**没有**能一网打尽的测试套件。后端 `business-system/tests/` 只有 Laravel 脚手架遗留的
`Feature\ExampleTest.php` / `Unit\ExampleTest.php`，**不要**用 `php artisan test` 冒充覆盖。

验证靠五层，代价从低到高。按改动面选最小充分集合，不要无脑全跑。

## 先决条件：服务在跑

第 ① ② ③ ④ 层依赖业务 API / 容器；**只有第 ⑤ 层不需要任何服务**。
API 不通时第 ① ② 层会红一片，而且报错长得跟"代码改坏了"一模一样。

```bash
deploy\status.bat                                  # 容器状态 + 健康检查
curl http://127.0.0.1:8000/api/v1/health           # 期望 {"code":0}
```

拿到 `{"code":0}` 才继续。不通先走 `ops-troubleshoot` skill。

## 选哪几层

| 改了哪里 | 必跑 |
|---|---|
| `mcp-server/`（工具 / 协议 / 传输） | ① + ② |
| `business-system/`（API / Service / 迁移 / 后台页面） | ①（走真实 HTTP，覆盖主要读写路径）→ ④ |
| `agent/`（图 / 节点 / 专家 / 工具 / prompt / RAG） | ③ + ④ |
| `agent/chat/auth.py`、`chat_manager.py`、`agent/src/.../obs.py`、`agent/tests/` | ⑤（若行为也进了图，再加 ④） |
| `agent/chat_server.py`、`agent/chat/notify_bridge.py` | ⑤ 不覆盖，手工 + `docker compose logs -f chat` |
| `eval/cases.jsonl` | ④ |
| 只有 `chat/static/index.html` | 手工点，见文末 |

## ⑤ Python 单测（最便宜，先跑）

```bash
python -m unittest discover -s agent/tests -v
```

- **24 条，通常 < 0.5s，不需要容器、不需要 API key**
- 覆盖三块：`chat/auth.py`（fail closed、cookie 提取、30s 缓存、`CHAT_AUTH_MODE=off`）、
  `chat/chat_manager.py`（owner 归属、`require_owned`、v1→v2 迁移、删除返回 thread_id）、
  `obs.py`（JSON 日志字段、Prometheus 指标、`TraceContext.bind` 与出站头）
- 成功：末尾 `Ran 24 tests in ...` + `OK`
- ⚠️ `agent/tests/test_router.py` **仍是 0 字节**，discover 会导入它但贡献 0 条测试。
  意图路由没有独立单测，别把 ⑤ 的绿灯当成路由被覆盖了
- 这层**不覆盖** `chat_server.py` 的路由/WS 处理、`notify_bridge.py`、PHP 后端

## ① MCP 单元冒烟

不走管道，直接 `$server->handle()`，最快。

```bash
php mcp-server/tests/client_smoke.php
```

成功：逐条 `  ✅ <标签>`，末尾 `全部通过 ✅`，退出码 0。
失败：`失败 N 项 ❌`，退出码 1。

依赖业务 API 真实可达（用的是真实订单号 `CE202608241027`），不是 mock。

## ② MCP 端到端（真实进程管道）

```bash
php mcp-server/tests/stdio_e2e.php
```

`proc_open` 拉起 `php bin/mcp-server.php`，走 stdin/stdout 换行 JSON 往返。
**改 stdio 传输或工具 handler 时必跑** —— 第 ① 层绕过传输层，管道问题测不出来。

## ③ LangGraph ↔ MCP 链路

```bash
python mcp-server/tests/langgraph_connect.py
```

验证 MCP 工具能挂成 LangChain 工具并驱动一轮「意图 → 工具调用 → 回答」。
**需要 `DEEPSEEK_API_KEY`**。无断言，纯人工判读 `✅` 行。

## ④ 34 条评测（最终闸门）

```bash
python eval/run_eval.py --retry 1
```

**通过标准是全过，没有阈值** —— `run_eval.py` 末尾就是
`return 0 if passed_n == total else 1`。

有用参数：

- `--category <中文类别>` —— 只跑一类。类别见 `cases.jsonl` 的 `category` 字段：
  订单查询 / 物流跟踪 / 退款售后 / 政策问答 / 报表任务 / 边界异常
- `--max N` —— 只跑前 N 条，冒烟用
- `--timeout <秒>` —— 单条超时，默认 120
- `--json <路径>` —— 写报告

**`--retry` 默认就是 1**：失败用例会换**全新 thread_id** 重跑（避免 checkpoint 串扰），
重试才过会标 `flaky=True`。看结果时**先看 flaky 那几条** —— 它们是最可疑的，
要么是 LLM 波动，要么是真 bug 被另一次采样掩盖了。

**没有「按 id 单跑某条 case」的参数。** 想单跑就临时裁 `cases.jsonl`。

报告结构：顶层 `total / passed / rate / flaky`，加 `by_category` 和
`results[]`（每条 `{id, category, input, passed, note, attempts, flaky}`）。

## 前端改动

`agent/chat/static/index.html` 是单文件零依赖前端，没有自动化测试。

```bash
docker compose up -d --build chat
```

手工点，重点验两处历史 bug（见 CHANGELOG v1.1.0）：

- 断线重连后发送是否还响应（旧 bug：busy 卡死，要刷新才恢复）
- nginx `/chat` 反代下 `BASE_PATH` 是否自适应

## 收尾

- 功能改动 → 更新 `docs/CHANGELOG.md`
- 架构级改动 → 同时更新 `docs/ARCHITECTURE.md`
- 新增 Python 依赖 → 同步写 `agent/requirements.txt`。
  **以它为准**，`pyproject.toml` 的 dependencies 已经落后于它；本地 `.venv` 有 ≠ 容器有
