# CLAUDE.md

跨境电商 ERP 智能体系统：客服与运营用自然语言完成订单查询、物流跟踪、售后退款、政策问答、数据报表。

## 不可破坏的三层不变量

1. **Agent 只做对话与编排，不直连数据库** —— 所有数据都经 `business-system` 的 REST API 获取。
2. **业务系统不感知 AI** —— 它不知道调用方是人还是 Agent，鉴权与业务校验一视同仁。
3. **资金类操作强制 human-in-the-loop** —— 退款必须经 interrupt 确认 + 管理员审批，
   不允许 Agent 绕过确认直接调退款接口。

动任何一处代码前，先确认不会打破以上三条。

## 目录布局

| 路径 | 内容 |
|---|---|
| `business-system/` | Laravel 12 后端（唯一事实来源）。路由前缀 `/api/v1`，双角色 token 鉴权 |
| `agent/` | Python LangGraph Agent + FastAPI 聊天服务。包根是 `agent/src/cross_ecommerce_agent/`（子包 `graph/ llm/ rag/ tools/` + 单文件 `config.py`、`obs.py` 可观测基座），入口 `chat_server.py`，`chat/` 会话管理（`chat_manager.py`）、登录态校验（`auth.py`）与通知桥接 + 前端 |
| `mcp-server/` | 原生 PHP 手写 MCP Server（JSON-RPC 2.0 + stdio 传输），14 个业务工具 |
| `eval/` | Agent 评测：`cases.jsonl`（37 条）+ `run_eval.py` |
| `deploy/` | 一键启停脚本 + `server-setup.md` 生产部署手册 |
| `docs/` | `ARCHITECTURE.md` / `PRD.md` / `CHANGELOG.md` / `screenshots/` |
| `docker-compose.yml` | 六个服务：postgres / rabbitmq / php-api / php-web / chat / php-worker |

> 根目录的 `AGENTS.md` 是 OpenClaw 工作区文件（本机环境笔记），**与本项目无关，不要读、不要改**。

## 常用命令

全部在仓库根目录执行（Windows + Docker Desktop）：

```bash
deploy\start-all.bat                              # 一键构建 + 启动全部容器
deploy\status.bat                                 # 容器状态 + 健康检查
deploy\stop-all.bat                               # 停止（保留数据卷；加 -v 会清库）
docker compose up -d --build                      # 单独重建
docker compose logs -f chat                       # Agent 服务日志
docker compose logs -f php-worker                 # 异步任务 / 审批事件日志
docker compose exec php-api php artisan migrate   # 执行迁移
curl http://127.0.0.1:8000/api/v1/health          # {"code":0} 即就绪
```

改完代码按代价从低到高验证：

```bash
php mcp-server/tests/client_smoke.php             # MCP 工具单元冒烟
php mcp-server/tests/stdio_e2e.php                # MCP 端到端（真实进程管道）
python mcp-server/tests/langgraph_connect.py      # LangGraph ↔ MCP 链路
python eval/run_eval.py --category 政策问答        # 单类回归（类别见 cases.jsonl）
python eval/run_eval.py --retry 1                 # 37 条评测，必须全过
python -m unittest discover -s agent/tests -v     # 多用户/可观测单测（24 条，不需要容器）
```

`business-system/tests/` 只有 Laravel 脚手架的 `ExampleTest`，**后端没有真正的测试套件**：
后端改动靠 MCP 冒烟 + eval 回归 + 手工打 `/api/v1` 验证。不要假装 `php artisan test` 覆盖了什么。

意图路由**没有独立测试**（`agent/tests/test_router.py` 目前是 0 字节空文件），
它的回归由 `eval/cases.jsonl` 的六类用例覆盖。

## 硬约束

- **鉴权**：`api.token:agent` / `api.token:admin` 两个角色，不可合并、不可放宽；唯一公开接口是 `health`。
- **多用户身份两段式**：token 只代表「机器角色」，员工身份由 chat 透传的 `X-User-Id / X-User-Name / X-User-Role`
  承载（`App\Support\TraceContext`）。新的写接口必须 `AuditLogger::log(...)`，否则审计链断裂。
- **聊天页登录**：复用 Laravel session（`GET /session/me`），不要再造账号/token；本地无 cookie 时
  用 `CHAT_AUTH_MODE=off`，默认 `on` 是 fail closed（验不了票就不放行）。
- **会话归属**：新增会话相关接口一律走 `manager.require_owned(session_id, user)`，禁止只按 session_id 取数据。
- **trace 透传**：跨层只透传 `X-Trace-Id`，不得改名或在中间层重新生成（缺头时才本地兜底生成）；
  详见 `docs/OBSERVABILITY_AND_ISOLATION.md`。
- **端口错开**本机原生服务：nginx `8000`、postgres `5433`、rabbitmq `5673` / `15673`；容器名统一 `ce-*`。
- **订单号**：`CE` + 12 位数字（`CE` + `YYYYMMDD` + 4 位序号）。物流单号 `CE-TRK-XXXXXX`，SKU 形如 `SKU-1028`。
- **查询性能**：列表模糊搜索必须走索引（`orders.order_no`、`customers.name/email`、`refunds.refund_no`
  上有 pg_trgm GIN；单号前缀用 `LIKE 'CE…%'` + `text_pattern_ops` 索引，查询前先 `strtoupper`）。
  **详情查询与写操作严禁模糊匹配**（安全考虑）。
- **退款双端**：后台页面审批走 `App\Http\Controllers\Web\RefundController`，API 走 `Api\RefundController`，
  它们是两个控制器；改退款逻辑必须双端同步，否则页面审批不发布事件、聊天端收不到通知。
- **前端**：原生单文件 JS（`agent/chat/static/index.html`），不引入框架、不加构建步骤；
  `BASE_PATH` 需自适应 nginx `/chat` 反代。
- **密钥**：`.env` 永不提交；聊天服务里 `RABBITMQ_PASSWORD` 以 compose 的 `environment` 为准（它会覆盖 `env_file`）。
- **文档同步**：功能改动更新 `docs/CHANGELOG.md`；架构级改动同时更新 `docs/ARCHITECTURE.md`。
- **长期记忆**：每次需求改动完成后，必须把**跨会话仍然有价值、且代码里读不出来**的结论写进 Claude Code
  长期记忆（`~/.claude/projects/E--cross-ecommerce-agent/memory/`，一条事实一个文件，
  并在 `MEMORY.md` 追加一行索引）。判断标准是"下一个会话的人会不会因此少踩一次坑"：
  - **该记**：做过但没落进代码的决策与理由、排查得出的根因、用户的偏好与反馈、
    外部依赖/环境约束（密钥来源、端口、第三方配额之类）。
  - **不该记**：`git log` / `docs/CHANGELOG.md` / 代码本身已经能回答的内容 —— 那是"改了什么"，不是记忆。
  - 写入前先查是否已有同主题文件，有则更新，不新建重复项。

## 已知坑（都踩过，别重复踩）

- **LangGraph 流式**：`graph.astream` + 同步 PostgresSaver → `NotImplementedError(aget_tuple)`。
  用同步 `graph.stream` + `loop.run_in_executor` + `asyncio.Queue` 桥接（见 `chat_server.py`
  的 `run_graph_sync` / `run_graph_and_stream`；该处注释写的 `asyncio.to_thread` 与实现不符，以代码为准）。
- **上下文裁剪**：裁剪点必须对齐 AI(`tool_calls`) / ToolMessage 的回合边界，否则请求格式 400。
- **Windows 上 PHP 管道**：`stream_set_timeout` 对 `fgets` 不生效 → 超时与 EOF 分不开。
  规避在**测试端** `mcp-server/tests/stdio_e2e.php` 的 `$readLine`（deadline 轮询 +
  `stream_get_meta_data()['timed_out']` 判别）；服务端 `bin/mcp-server.php` 仍是阻塞 `fgets(STDIN)`。
- **0 字节占位文件**：`agent/config/business_api.yaml`（无代码读取）、`agent/tests/test_router.py`、
  `agent/src/cross_ecommerce_agent/main.py`、`tools/mcp_client.py`、`scripts/ingest_docs.py`。
  别照文件名假设它们有内容（真正的 RAG 入库逻辑在 `rag/ingest.py` 的 `__main__`）。
- **composer 被中断**：会留孤儿 git 进程（卡在 `git clone --mirror`），锁目录删不掉 → kill 后即可删。
- **Alpine 脚本**：`.sh` 必须 LF 无 BOM，否则 `exec format error`。
- **RAG 空库**：全新环境的空 Chroma 会让 BM25Okapi 除零 → 必须有空库保护（跳过 BM25 路）。
- **容器文件权限**：worker 以 root 写导出文件，FPM(www-data) 读不到 → 导出后 chmod；
  bind mount 的 `storage` / `bootstrap/cache` 同理（`a+rwX`）。
- **nginx DNS 缓存**：依赖服务晚启动会缓存旧 IP → 502（症状：nginx unhealthy 但 php-fpm 正常），
  `docker exec ce-php-web nginx -s reload` 可恢复。
- **embedding 端点**：百炼单次最多 10 条文本（`BATCH_SIZE=10`）；不要走默认 tiktoken 转换
  （兼容端点只认字符串输入）。
- **Docker 冷启动**：PG 首次建库可能超过 60s，等库就绪要用 `PDO SELECT 1` 循环，不能只探端口。
- **队列 Job 的 trace 上下文**：worker 是常驻进程，`RunTaskJob::handle()` 开头必须
  `TraceContext::reset()` 再 `setTraceId()`，否则会把上一条链路的 trace_id / 用户串到下一个任务上。
- **聊天服务 fail closed**：`BIZ_WEB_BASE` 未配置或后台不可达时，chat 一律 401（宁可不放行）。
  本地直连 8082 调试必须显式 `CHAT_AUTH_MODE=off`；容器内由 compose 注入 `BIZ_WEB_BASE=http://php-web`。
- **会话文件迁移**：`chat_sessions.json` 已升 v2（`owner_id/owner_name`），v1 老条目自动归
  `owner_id=0「(未归属)」`、仅管理员可见；别手动补 owner 时写成字符串 `"0"`（比较用的是 int）。
- **日志双写**：`LOG_STACK=daily,json`，`storage/logs/laravel.json.log` 才是采集/检索用的；
  排查"日志里没有 trace_id"先看是不是只改了 `daily` 而 stack 没带上 json。
- **trace 表会涨**：`trace_requests` 每个请求一行，保留期配置在 `config/observability.php`，
  清理任务属 P2；本地造数/压测后记得 `TRUNCATE trace_requests` 再跑评测。

## 代码约定

- **PHP**：新增文件统一 `declare(strict_types=1)`（**现状：`app/Support/TraceContext.php`、`AuditLogger.php`、
  `Middleware/TraceRequest.php` 等 v1.2 新增文件已采用；老文件仍未加 —— 改老文件时不要顺手全量加，避免大 diff**）；
  控制器保持薄，业务逻辑进 Service；校验用控制器内联 `$request->validate()`（仓库无 FormRequest 先例），
  响应统一走 `app/Support/ApiResponse.php`；
  路由集中在 `routes/api.php`（业务 API）与 `routes/web.php`（后台页面）。
- **Python**：包根 `agent/src/cross_ecommerce_agent/`，按职责分子包（`graph/ llm/ rag/ tools/`）
  + 单文件 `config.py`；全部绝对导入（`from cross_ecommerce_agent.xxx import ...`，无相对导入）；
  新增依赖必须同步写进 `agent/requirements.txt`（容器里缺包是常见事故，本地 `.venv` 有 ≠ 容器有；
  注意 `pyproject.toml` 的 dependencies 已落后于 requirements.txt，以 requirements.txt 为准）。
- **前端**：单文件、零依赖、零构建；注释用中文。
- **文档/注释**：中文为主，术语保留英文原名（checkpointer、interrupt、RAG 等）。

## 项目 Skill

`.claude/skills/` 下有 6 个项目专属 skill（按需自动触发）。动对应子系统前先读：

| Skill | 用途 |
|---|---|
| `verify-change` | 改动后该跑哪几层验证、哪些是假绿灯 |
| `refund-flow` | 退款全链路 + Api/Web 双端同步点 + 事件 payload 契约 |
| `agent-graph-dev` | Agent 图拓扑、节点/专家/工具落位、流式与上下文裁剪硬约束 |
| `api-endpoint-dev` | 后端接口/Service/迁移、双角色鉴权、索引二分策略 |
| `mcp-server-dev` | MCP 工具注册、协议分发、"14 个工具"硬编码位置 |
| `ops-troubleshoot` | 容器/502/通知丢失 —— 症状→根因→命令排查表 |
