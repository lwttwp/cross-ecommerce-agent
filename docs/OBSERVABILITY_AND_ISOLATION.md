# 多用户隔离 + 全链路日志监控 设计与落地

| 项目 | cross-ecommerce-agent |
|---|---|
| 版本 | v1.1（P1 落地） |
| 日期 | 2026-11-17 |
| 状态 | P1 已实现，P2/P3 待排期 |

---

## 1. 目标

1. **多用户隔离**：客服 / 运营 / 管理员多账号登录，各自只看自己的会话与审批通知；所有写操作能追溯到"哪个员工在什么链路下做的"。
2. **全链路可观测**：一次用户提问从 WebSocket 进来到业务库落库，能用**一个 trace_id** 串起 chat → LangGraph → 工具 → 业务 API → RabbitMQ → Worker → 审批通知，失败时可定位到具体环节。

前提：不破坏 `CLAUDE.md` 的三层不变量（Agent 不直连业务库、业务系统不感知 AI、资金操作 human-in-the-loop）。

---

## 2. 现状盘点（改之前）

| 环节 | 现状 | 问题 |
|---|---|---|
| 聊天鉴权 | `chat_server.py` 的 `/ws`、`/api/sessions*` 全部匿名可访问 | 任何人拿到 `session_id` 就能读别人整段对话（IDOR） |
| 会话归属 | `agent/chat/chat_sessions.json` 只有一个扁平 `sessions` 字典 | 无 owner 概念，`GET /api/sessions` 返回全站会话 |
| 业务身份 | `ApiTokenAuth` 只认 `agent` / `admin` 两个共享 token | 所有员工共用一个身份，审计无处可落 |
| 审批人 | `OrderService::approveRefund()` 里 `User::where('role','admin')->value('id')` | 永远记成"第一个管理员"，多账号后是错的 |
| 数据权限 | 后台所有页面所有登录用户可见 | 普通客服能审批退款 |
| 日志 | Python `logging.basicConfig` 纯文本；Laravel 默认 monolog 单文件 | 无 trace_id、无结构化字段、跨服务无法串联 |
| 链路追踪 | 只有 `docs/ARCHITECTURE.md` 里"LangSmith（可选）" | 实际未接入，出问题只能 `docker compose logs` 人肉拼 |
| 指标 | 只有 docker healthcheck | 无 QPS / 延迟 / 失败率，无法判断"慢在哪一层" |
| 审计 | `order_status_logs` 只记订单状态流转文本 | 退款审批、任务导出等无操作者记录 |

---

## 3. 多用户隔离设计

### 3.1 身份来源：复用 Laravel session（不新建账号体系）

`users` 表已有 `email/password/role(agent|admin)`，后台已用 session 登录。聊天页与后台**同源**（`http://127.0.0.1:8000/chat/` 和 `/admin`），
浏览器天然会把 `laravel_session` cookie 带到 `/chat/*`，因此：

```
浏览器 --(cookie)--> nginx /chat/ --> chat:8082
                                      └--(转发 Cookie)--> http://php-web/session/me
                                                             └--> {id,name,email,role}
```

- 新增 `GET /session/me`（web 中间件，未登录返回 401 JSON，不重定向）。
- chat 服务对 **每个 HTTP 请求 + 每次 WS 握手** 校验，结果按 cookie 做 30 秒内存缓存（避免每条消息都回查）。
- 未登录：HTTP 返回 `401`，WS 以关闭码 `4401` 结束；前端跳 `/login?redirect=<当前路径>`。
- 逃生阀：`CHAT_AUTH_MODE=off` 时用本地调试账号（默认 `on`，生产禁止关闭）。

### 3.2 会话归属

`chat_sessions.json` 升到 v2：

```json
{ "version": 2,
  "sessions": { "s-xxx": { "thread_id": "chat-xxx", "title": "...",
      "owner_id": 7, "owner_name": "客服小王",
      "created_at": "...", "last_active": "..." } } }
```

- 新建会话写入当前用户；`GET /api/sessions` 只返回自己的；`admin` 可见全部（排障用，行上带 `owner_name`）。
- `GET /api/sessions/{id}/messages`、`DELETE /api/sessions/{id}`、`WS /ws?session_id=` 都做归属校验，越权返回 403 / 关闭码 `4403`。
- 旧数据（无 owner）自动归到 `owner_id=0`（"迁移未归属"），仅管理员可见，不丢历史。

### 3.3 下游透传与审计

chat → 业务 API 的每个请求带三个头（`BusinessApiClient` 统一注入）：

```
X-Trace-Id: 8f3c...            # 贯穿全链路
X-User-Id:  7
X-User-Name: 客服小王
X-User-Role: agent
```

- `ApiTokenAuth` 解析后写入 `request.attributes['api_user']`，并注入日志上下文；
- 写操作落 `audit_logs`：退款申请/审批、订单取消/改地址/创建、任务创建与导出；
- 退款单新增 `requested_by_user_id / requested_by_name`（谁提的）、`approver_id` 改为**真实审批人**；
- 审批事件 payload 增加 `trace_id / actor`，Python 消费后写进结构化日志，通知链路也能回查。

### 3.4 权限矩阵（P1）

| 能力 | agent（客服/运营） | admin（管理员） |
|---|---|---|
| 聊天问答、查单、提交退款 | ✅ | ✅ |
| 查看自己的会话 / 历史 | ✅ | ✅ |
| 查看所有人的会话 | ❌ | ✅ |
| 后台审批退款 | ❌（本轮收紧） | ✅ |
| 查看链路 / 审计 | ❌ | ✅ |

> 收紧审批权限是行为变更：`/admin/refunds/{id}/approve|reject` 与 `/admin/traces*` 走 `role:admin` 中间件，页面按钮同步隐藏。

---

## 4. 全链路日志监控设计

### 4.1 trace_id 生命周期

```
用户发一条消息
  └─ chat_server: trace_id = uuid4().hex[:16]           (span: ws.message)
       ├─ agent graph.stream  (span: agent.round, 含 intent / 工具列表 / 耗时 / 结果)
       │    └─ 工具 → httpx  → Laravel  (span: http.request, 头 X-Trace-Id)
       │                          ├─ 控制器 / Service
       │                          ├─ RabbitMQ publish (payload 带 trace_id)
       │                          └─ trace_requests 落库
       ├─ queue: RunTaskJob (span: queue.job, job payload 带 trace_id)
       └─ 审批事件消费 → notify_bridge (结构化日志带 trace_id) → WebSocket notice
```

关键点：**trace_id 在入口生成，之后所有层只透传不再生成**。缺头时各层自行兜底生成（保证日志永远有 id），但会标记 `trace_origin=local`。

### 4.2 落点清单

| 层 | 文件 | 内容 |
|---|---|---|
| chat | `agent/chat_server.py` | WS 建连/断开、消息、图执行耗时与异常、`/metrics` |
| agent | `agent/src/cross_ecommerce_agent/obs.py` | JSON 日志、contextvars（trace/user/session）、计数器与直方图 |
| 工具 | `agent/src/cross_ecommerce_agent/tools/business.py` | 每次 HTTP 调用的 path/状态/耗时/错误码 |
| 业务 API | `business-system/app/Http/Middleware/TraceRequest.php` | 每请求一行 `trace_requests`（method/path/status/ms/user/ip） |
| 业务系统 | `app/Support/TraceContext.php` + `ApiTokenAuth` | trace/user 注入日志上下文 |
| 队列 | `app/Jobs/RunTaskJob.php` | 消费时的 `trace_requests`（channel=queue） |
| 事件 | `RefundEventPublisher` | payload 带 `trace_id/actor` |
| 消费 | `agent/chat/notify_bridge.py` | 事件日志带 trace_id，通知与链路对上 |

### 4.3 日志规范（两服务一致）

```json
{"ts":"2026-11-17T03:12:44.918Z","level":"INFO","service":"agent",
 "event":"tool.call","trace_id":"8f3c2d1a9b7e4f60","session_id":"s-abc",
 "user_id":7,"duration_ms":132,"path":"/orders/CE2026...","status":200}
```

- Python：`obs.JsonFormatter` 输出单行 JSON 到 stdout（docker 采集）+ `agent/logs/agent.log`；
- PHP：新增 `json` 日志通道（`storage/logs/laravel.json.log`），通过 `LOG_STACK=daily,json` 启用；
- 字段命名统一 `snake_case`，必带 `ts/level/service/event/trace_id`；
- 严禁把 token、密码、完整地址、手机号明文写进日志（复用现有脱敏输出）。

### 4.4 指标（`GET /metrics`，Prometheus 文本格式）

| 指标 | 类型 | 用途 |
|---|---|---|
| `ce_ws_connections_active` | gauge | 在线会话数 |
| `ce_chat_messages_total{result}` | counter | 吞吐与失败率 |
| `ce_agent_round_seconds` | histogram(sum/count) | 单轮延迟 |
| `ce_tool_calls_total{path,ok}` | counter | 工具健康度（`path` 已归一为 `/orders/{id}` 等模板） |
| `ce_tool_call_seconds` | histogram | 工具耗时 |
| `ce_agent_errors_total` | counter | 图执行异常 |

零依赖实现（`obs.Metrics`），够用即可；后续接 Prometheus 只要加 scrape 配置。

> `/metrics` 需带 `Authorization: Bearer $METRICS_TOKEN`；未配置 token 时一律 403，
> 避免该端点经 `/chat` 反代公开暴露（指标含会话数、工具路径等运行信息）。

### 4.5 后台查看

`/admin/traces`：按 trace_id / 渠道 / 只看错误 / 时间范围过滤；点 trace_id 进详情，看到该链路所有 span（chat、agent、http、queue）+ 关联审计记录。定位一个失败请求从"翻三个容器日志"变成"点一下"。

---

## 5. 数据模型（P1 新增）

```sql
-- 每个 span 一行
trace_requests(
  id bigserial, trace_id varchar(40) index, span varchar(60),
  channel varchar(16),          -- http|agent|queue|event
  method varchar(8) null, path text null, route varchar(120) null,
  status int null, duration_ms int null,
  user_id bigint null, user_name varchar(64) null, role varchar(16) null,
  ip varchar(45) null, session_id varchar(40) null,
  meta jsonb null, created_at timestamp index
)

-- 写操作审计
audit_logs(
  id bigserial, trace_id varchar(40) index,
  user_id bigint null, user_name varchar(64) null, role varchar(16) null,
  action varchar(60) index,       -- refund.apply|refund.approve|order.cancel|task.create…
  target_type varchar(40) null, target_id varchar(64) null,
  payload jsonb null, ip varchar(45) null, created_at timestamp index
)

-- refunds 增加申请人
alter table refunds add requested_by_user_id bigint null,
                  add requested_by_name varchar(64) null;
```

---

## 6. 阶段拆解

### P1（本轮已实现）

- 身份：`/session/me`、chat 侧鉴权与 30s 缓存、`CHAT_AUTH_MODE` 逃生阀
- 隔离：会话 v2 归属、列表/历史/删除/WS 四道校验
- 审计：`audit_logs`、真实审批人与申请人、角色守卫
- 链路：trace_id 贯穿 chat→agent→HTTP→队列→事件，`trace_requests` 落库
- 观测：JSON 日志、`/metrics`、`/admin/traces` 查询页

### P2（下阶段建议）

1. **数据权限细化**：按 `orders.owner_user_id`（客服负责制）过滤列表，Agent 工具侧同样按用户过滤；
2. **告警**：错误率 / P95 延迟阈值 → 企业微信或邮件；`trace_requests` 保留期与分区（按月）；
3. **MCP 链路补齐**：`mcp-server/src/BusinessApiClient.php` 透传 `X-Trace-Id`（当前 compose 未启用 MCP，先留接口）；
4. **日志采集**：Loki + Promtail 或 `docker logs` → 统一检索，替换现在的容器内文件；
5. **会话共享/交接**：会话转交、管理员接管，配合 `session_participants` 表。

### P3（长期）

- 多租户（`tenant_id` 全表 + 行级权限）—— 当前业务只有一家公司，属过度设计，留好 `owner_*` 字段即可平滑演进；
- OpenTelemetry 标准 span 导出（Tempo/Jaeger），与自建 `trace_requests` 双写过渡；
- 成本可观测：按用户/会话统计 token 消耗与费用。

---

## 7. 验收清单（P1）

| # | 场景 | 期望 |
|---|---|---|
| 1 | 未登录访问 `/chat/` | 跳转 `/login?redirect=/chat/`，WS 关闭码 4401 |
| 2 | 客服 A 登录后新建会话，客服 B 登录 | B 的会话列表看不到 A 的会话；直接请求 A 的 `messages` 返回 403 |
| 3 | 用 A 的 session_id 连 WS | 关闭码 4403，不产生任何图执行 |
| 4 | 客服提交退款 | `refunds.requested_by_name` = 客服姓名；`audit_logs` 有 `refund.apply` 且带 trace_id；事件 payload 带 actor |
| 5 | 管理员页面审批 | `refunds.approver_id` = 当前管理员 id（不再是"第一个 admin"）；客服账号访问审批接口 403 |
| 6 | 一次问答 | `trace_requests` 里同 trace_id 至少 3 行：`agent.round`、`http.request`（/orders…），耗时可按层拆 |
| 7 | 触发报表任务 | 队列 span 与该 trace 关联（job payload 带 trace_id） |
| 8 | 图执行异常 | `/metrics` 的 `ce_agent_errors_total` +1；`/admin/traces` 按"只看错误"能查到 |
| 9 | 日志 | 两服务日志均为单行 JSON，含 `trace_id/user_id/event`，无 token 明文 |

---

## 8. 风险与回滚

| 风险 | 处置 |
|---|---|
| 鉴权上线导致本地 8082 直连调试失效 | `CHAT_AUTH_MODE=off` 显式关闭；默认 on |
| 会话文件升级丢数据 | 读取时兼容 v1 结构并原子回写 v2；旧条目归 `owner_id=0` |
| trace 落库拖慢请求 | 中间件在 `terminate()` 写、整段 try/catch 吞异常、健康检查与静态资源跳过；`TRACE_ENABLED=false` 可整体关闭 |
| 审批权限收紧影响现有账号 | `admin@example.com` 仍是 admin；客服账号需管理员代审 |
| 日志体积 | JSON 通道单独文件；P2 加保留期与轮转 |
| **身份头可伪造** | API 侧 `X-User-*` 仍只有 token（agent/admin）把关，若 Agent 被攻破可伪造员工身份；Web 请求已改为以登录 session 为准，不再采信头。P2 方案：chat 用共享密钥对 `trace_id + user_id + ts` 做 HMAC，业务系统验签后才采信；在此之前审计结论只能当"可信内网"级别使用 |
