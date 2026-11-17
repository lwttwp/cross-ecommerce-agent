---
name: api-endpoint-dev
description: 在 business-system/（Laravel 12 后端）下新增或修改 API 接口、Service、数据库迁移、后台页面时使用。这个后端的约定和 Laravel 默认套路不同：校验内联不建 FormRequest、响应统一走 ApiResponse、双角色 token 不可放宽、查询的索引策略按「模糊搜索 vs 详情与写操作」严格二分；v1.2 起写接口还必须落 audit_logs 并透传 trace/身份头。用户要加接口、改控制器、写 Service、建迁移、调查询性能、改后台页面、动审计或全链路追踪时用这个 skill。
---

# 后端接口开发

`business-system/` 是**唯一事实来源**。Agent 和 MCP Server 都只能通过它的 REST API 拿数据，
不能直连库。

## 加一个接口：三处 + 一条审计

### 1. 路由 `routes/api.php`

全部包在 `Route::prefix('v1')` 里（`:12`），按角色分三块：

- 公开：只有 `GET v1/health`（`:14`）。**新增接口一律不许放公开组。**
- `api.token:agent`（`:17`）—— 查询与业务写单，外加内部链路接口 `POST internal/traces`（`:38`，
  由 Agent 上报每轮对话的 span，不是业务 API）
- `api.token:admin`（`:42`）—— 目前只有退款 approve / reject

路由参数用**业务单号** `{orderNo}` / `{taskNo}`，不是自增 id（例外：退款审批用 `{id}`）。

后台页面路由在 `routes/web.php`：`/session/me` 是 chat 的 SSO 校验口（session 校验，未登录 401 JSON，
不是 API）；`/admin/*` 走 `auth`；退款审批与 `/admin/traces*` 额外挂 `role:admin`。

### 2. 控制器 `app/Http/Controllers/Api/XxxController.php`

**保持薄** —— 只有 `$request->validate()` + 取参 + `try`，业务逻辑全部下沉到 Service。

### 3. Service `app/Services/XxxService.php`

业务逻辑、状态机、`DB::transaction` 落在这里。

最干净的范本：`Api\RefundController.php`（构造注入 Service + `ApiResponse` 返回）
与 `app/Services/OrderService.php`（常量状态机 + 事务）。

### 4. 写操作必须落审计

**有副作用的操作在业务成功后调用**（顺序不能反，失败的操作不该留成功审计）：

```php
AuditLogger::log('order.cancel', 'order', $order->order_no, [
    'status_before' => 'PENDING_PAYMENT',
]);
```

- 入口 `app/Support/AuditLogger.php`，签名 `log(action, targetType, targetId, payload)`
- `action` 用 `domain.verb`：现有 `order.create|address|cancel`、`refund.apply|approve|reject`、
  `task.create`
- `AuditLogger` **写失败只记 warning、不抛异常** —— 漏调不会报错，只会让审计链静默缺失
- API 与后台页面两端都要记（退款双端的现有实现：`Api\RefundController.php:92,125`、
  `Web\RefundController.php:78,113`）
- 批量写操作要显式定三件事：事务边界（全成全败 vs 部分成功）、审计粒度（建议逐单一条
  `order.cancel`，不要只写一条汇总）、批量上限。这三项没有现成规范，落地前先定。

## 多用户身份：token 是机器，员工靠头

- `config/api.php` 只有两个 token，来自 `.env` 的 `API_TOKEN_AGENT` / `API_TOKEN_ADMIN`，
  `ApiTokenAuth` 用 `hash_equals` 常量时间比较。**不可合并、不可放宽。**
- GET 下载场景允许 `?token=`；**写操作仍强制 `Authorization` 头**（`ApiTokenAuth.php:28-32`）
- token 只代表机器角色。员工身份由 `TraceRequest` 采信上游头（`TraceRequest.php:42-50`）：
  - `X-User-Id` **必须是纯数字**才采信，否则忽略
  - `X-User-Role` 仅接受 `agent` / `admin`，其他值降级为 `agent`
  - `X-User-Name` 去控制字符并截断到 64 字符
- 没有上游身份时，`ApiTokenAuth` 退化写成机器身份 `api:agent` / `api:admin`（`:53-59`）
- 业务代码读 `TraceContext::userId()/userName()/role()`，**不要自己解析 header**；
  也不要在业务系统里做员工级鉴权——那会破坏「业务系统不感知 AI」（不变量 2）
- 需要回答「谁做的」的写流程，要持久化操作人。现有落点：`refunds.requested_by_user_id/name`
  （`2026_11_17_000003`）、`refunds.approver_id`（`2026_08_24_000006`）、
  `tasks.created_by`（`2026_08_24_000007`）、`order_status_logs.operator`（`2026_08_24_000005`，
  存姓名快照，不是员工 id）
- ⚠️ `X-User-*` 目前只由机器 token 把关，内网可信级别，**可伪造**；详见
  `docs/OBSERVABILITY_AND_ISOLATION.md` §8

## 审计 vs 链路

两张表职责不同，别混：

| 表 | 记什么 | 写入点 |
|---|---|---|
| `audit_logs` | 有副作用的操作（合规/追责） | 控制器业务成功后 `AuditLogger::log` |
| `trace_requests` | 每个请求/每次执行的技术 span | `TraceRequest::terminate()`、`RunTaskJob`、`TraceIngestController` |

- `TraceRequest` 在 API 侧 **prepend**、在 Web 侧 append（`bootstrap/app.php:23-29`），
  健康检查、静态资源、下载不落库；异常一律吞掉，不影响业务
- `X-Trace-Id` 跨层**只透传、不重新生成**；`TraceContext::adopt()` 只接受 8-40 位
  `[A-Za-z0-9_-]`，缺头才本地生成
- 队列 Job 是常驻进程：`RunTaskJob::handle()` 开头必须 `TraceContext::reset()` 再 `setTraceId()`，
  否则上一条链路的 trace/user 会串到下一个任务
- 全局逃生阀 `TRACE_ENABLED=false`（`config/observability.php`），排障时可临时关

## 与 Laravel 默认套路不同的地方

- **不建 FormRequest** —— `app/Http/Requests/` 目录不存在。校验用控制器内联
  `$request->validate([...])` + 数组规则。示例：`Api\OrderController::store`（`:74-88`）、
  `Api\RefundController::store`（`:24-27`）。枚举值用 `Rule::in(XxxType::values())`
  （见 `Api\TaskController.php:28`）。
- **响应统一走** `app/Support/ApiResponse.php`（`ok` / `fail`），格式 `{code,message,data}`。
  别直接 `response()->json()` 自造格式。
- **业务异常** `app/Exceptions/BusinessException.php`（带 `businessCode` + `httpStatus`），
  控制器捕获后转成用户可读反馈。
- **`declare(strict_types=1)`** —— 新增文件必须加。现状是**新旧混用**：`app/` 下已有 12 个文件采用
  （`Support/AuditLogger|TraceContext`、`Middleware/ApiTokenAuth|RequireRole|TraceRequest`、
  `Api/TraceIngestController`、`Web/RefundController|TraceController`、`Jobs/RunTaskJob`、
  `Models/TraceRequest|AuditLog`、`Services/RefundEventPublisher`）。注意 `Support/ApiResponse.php`
  和 `Middleware/ForceJsonResponse.php` **没有**；全项目另有 3 个 `2026_11_17_*` 迁移和
  `config/observability.php` 也有。老控制器大多没加，**改老文件不要顺手全量补**，避免大 diff。

## 查询：索引策略严格二分 ← 最容易踩

两类查询策略**不能混**。

**列表 / 模糊搜索**（用户输入关键词）—— 可以模糊：

- `%kw%` 前后通配 → 走 GIN `pg_trgm`（`orders.order_no`、`customers.name/email`、
  `refunds.refund_no`）。**关键词需 3 个字符以上**才有效。
- 单号前缀 `LIKE 'CE…%'` → 走 `text_pattern_ops` B-tree（`orders.order_no`、`refunds.refund_no`）。
  **只支持大小写敏感 LIKE**，所以查询前必须 `strtoupper($keyword)`。
- 索引迁移：`2026_09_02_000001`（trgm）、`_000002`（refund_no trgm）、`_000003`（text_pattern_ops）
- 现成实现：`Api\OrderController::index`（`:23,34-42`）、`Api\RefundController::index`（`:61-65`）、
  `Web\OrderController::index`、`Web\RefundController::index`（`:34-47`）

**详情查询与写操作** —— **严禁模糊匹配**。
安全考虑：模糊命中多单会破坏业务校验（可能退错单）。一律精确匹配。

> 历史教训：百万订单时 `ILIKE '%kw%'` 全表扫 → 加 trgm GIN 后降到 30ms。
> 加新的搜索字段记得同步建对应索引，别让列表页退化成全表扫。

## 迁移

`database/migrations/`，命名 `YYYY_MM_DD_NNNNNN_描述.php`。
现有业务表集中在 `2026_08_24_000001` ~ `000008`；v1.2 新增：

- `2026_11_17_000001_create_trace_requests_table.php`
- `2026_11_17_000002_create_audit_logs_table.php`
- `2026_11_17_000003_add_requester_to_refunds_table.php`

```bash
docker compose exec php-api php artisan migrate
```

要加搜索索引就照 `2026_09_02_000001` 的写法：先 `CREATE EXTENSION IF NOT EXISTS pg_trgm`，再建 GIN。
注意 PG 首次建库可能要 60s+，别急着判失败。

## 常量硬编码在 Service 里，没有配置文件

- 订单号 `'CE'.date('Ymd').4位随机数`（`do/while` 查重）—— `OrderService::generateOrderNo`（`:37`）
- 退款单号 `'RF'.date('Ymd').4位随机数`（同样查重）—— `OrderService::generateRefundNo`（`:46`）
- 状态机 `TRANSITIONS`（`:21`）、可改址 `ADDRESS_EDITABLE`（`:32`）、可退款 `REFUNDABLE`（`:35`）
- 任务号 `'TSK'` + 6 位随机数 —— `Api\TaskController::store`（`:33`）
- 物流单号前缀 `CE-TRK-` 在 `Web\OrderController.php:204`
- 唯一的业务配置文件是 `config/api.php`（双角色 token）

⚠️ 订单号格式是**隐式契约**：`graph/router.py` 用 `CE\d{12}` 做规则预检与指代兜底，
`eval/cases.jsonl` 的用例也全是 12 位。改生成格式会同时打断路由预检和评测，不要只改一处。

## 双端注意

后台页面（`Web\*Controller`，`routes/web.php`，前缀 `/admin`，session `auth` 中间件）和
API（`Api\*Controller`）**是两套控制器**。退款审批逻辑两边各一份，改必须同步 —— 见 `refund-flow` skill。
其他列表页的筛选实现也常常两边各有一份。

注意 Web 端的退款**申请**在 `Web\OrderController::refund`，`Web\RefundController` 只管审批页。

## 验证

**后端没有真正的测试套件**（`tests/` 只有脚手架 `ExampleTest`）。
不要假装 `php artisan test` 覆盖了什么；`python -m unittest discover -s agent/tests` 只测 Python/chat，
不覆盖 PHP。

改完靠：

```bash
php mcp-server/tests/client_smoke.php   # 走真实 HTTP，覆盖主要读写路径
python eval/run_eval.py --retry 1       # 端到端
```

再手工打 `/api/v1` 验边界。改了 Service 记得**双端各点一次**（API + 后台页面）。
动了写接口还要确认 `audit_logs` 里出现对应 `action`；动了 trace 相关，查
`trace_requests` 里同 trace_id 是否成链。功能改动同步更新 `docs/CHANGELOG.md`。
