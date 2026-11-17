# Changelog

## v1.2.0（2026-11-17）— 多用户隔离 + 全链路日志监控（P1）

设计文档：`docs/OBSERVABILITY_AND_ISOLATION.md`（含 P2/P3 拆解与验收清单）

### 多用户隔离
- **聊天页 SSO**：复用 Laravel session（同源 cookie）→ chat 服务调 `GET /session/me` 验票，
  未登录跳 `/login?redirect=…`；`CHAT_AUTH_MODE=off` 仅供本地调试
- **会话归属**：`chat_sessions.json` 升 v2（`owner_id/owner_name`），列表/历史/删除/WS 四道归属校验；
  越权 HTTP 403、WS 关闭码 4403；管理员可见全部会话（排障接管），v1 老数据自动迁移为"未归属"
- **身份透传**：chat → 业务 API 增加 `X-Trace-Id / X-User-Id / X-User-Name / X-User-Role`，
  订单状态日志的 `operator` 从 "agent" 变成员工姓名
- **审计**：新增 `audit_logs`（退款申请/审批、订单创建/取消/改址、任务创建），
  含 trace_id、操作人、参数快照
- **审批人修复**：`approver_id` 写入真实审批人（原来取「第一个 admin」，多账号后是错的）；
  退款单新增 `requested_by_user_id / requested_by_name`
- **权限收紧**：后台退款通过与链路查询页走 `role:admin`；客服账号看得到"待管理员审批"但点不了

### 全链路日志监控
- **trace_id 贯穿**：一条消息一个 id，串起 chat(WS) → LangGraph → 工具 → 业务 API → RabbitMQ → Worker → 审批通知
- **`trace_requests` 表**：`http / agent / queue / event` 四类 span，含耗时、状态、操作人、session_id
- **Laravel 中间件 `TraceRequest`**：采信上游 `X-Trace-Id`（不合法则本地生成）、注入日志上下文、
  响应头回写；`terminate` 阶段落库且异常吞掉，绝不拖慢接口
- **结构化日志**：Python `obs.py` 单行 JSON（ts/level/service/event/trace_id/user_id）；
  Laravel 新增 `json` 日志通道，默认 `LOG_STACK=daily,json` 双写
- **指标**：`GET /metrics`（chat 服务，Prometheus 文本）——在线连接数、消息数、工具调用/耗时、图异常
- **后台页面**：`/admin/traces` 按 trace_id / 渠道 / 只看错误 / 时间过滤，详情聚合 span + 审计
- **Agent 上报**：每轮对话后上报 `POST /api/v1/internal/traces`（Agent 仍不直连业务库）

### 审查修复（同日）
- **Web 审计身份**：`TraceRequest` 在 `handle()` 阶段用登录 session 用户填充 `TraceContext`，
  后台退款申请/审批的 `audit_logs` 与事件 `actor` 不再为空；Web 请求不再采信 `X-User-*`，防止伪造
- **trace 完整性**：Agent 上报 `internal/traces` 透传 `X-Trace-Id`，新增 `ws.message` 入口 span；
  内部上报路径不再落 `http.request`，消除每轮对话的孤儿链路
- **逃生阀**：`TRACE_ENABLED=false` 同时关闭 Agent 上报、队列与事件 span（此前只关 HTTP 中间件）
- **指标**：工具指标 `path` 归一为 `/orders/{id}` 等模板，消除无界基数；`/metrics` 需 `METRICS_TOKEN`
- **鉴权语义**：会话越权改为真实 HTTP 403（此前 body code 403 / HTTP 200）
- **本地调试**：`CHAT_AUTH_MODE=off` 的 `id=0` 不再下发/写入，避免 `tasks.created_by` 外键失败
- **日志**：json 通道固定为 `storage/logs/laravel.json.log`（`single` 驱动）
- **匿名流量**：未通过 token 鉴权的 API 请求不再写 `trace_requests`
- **管理员会话**：首次进入不再自动接管他人最近会话，优先自己的会话，没有就新建
- **审计补全**：Web 订单创建/支付/发货/签收补 `AuditLogger`；`api_user` 属性按文档落点
- **trace 来源**：`trace_requests.meta.trace_origin` 标记 `upstream|local`，日志上下文同步
- **事件循环**：历史读取/checkpoint 删除改为 `asyncio.to_thread`；span 上报改固定线程池
- **幂等**：重复取消已取消订单返回 40901，不再二次返还库存
- **通知消费**：`refund_consumer.py` 注明与 chat 内置 `ChatEventBridge` 竞争同一队列，不可同时运行

### 用户管理（后台，仅管理员）
- 新增 `/admin/users`：列表、姓名/邮箱搜索、角色/状态筛选、新增、禁用/启用、软删除
- `users` 加 `status` + `deleted_at`；禁用后登录被拒，后台下一次请求即登出，`/session/me` 返回 401
- 审计 `user.create / user.enable / user.disable / user.delete`；不能禁用/删除自己或最后一个启用中的管理员

### RAG 权限分级
- 知识库 chunk 增加 `access_level`（`agent` / `admin`）；`dispute_resolution_policy.md` 仅管理员可见
- 向量路走 Chroma metadata filter，BM25 路按角色预建索引——两条路都过滤，缺 metadata 默认 `agent`
- `rag_node` 从 `obs.current_user()` 取角色；评测用例支持可选 `user_role`，新增 3 条边界用例（共 37 条）
- 本次采用蓝绿重建：新库 `data/chroma_v2` + 新 collection `aftersale_policy_v2`，旧库 `data/chroma` 保留可回滚；
  后续改 `RAG_DOC_ACCESS` 或文档内容需重建（或按同样方式再做一次蓝绿切换）

### 测试
- 新增 `agent/tests/test_chat_manager.py`（归属/迁移）、`test_obs.py`（trace/日志/指标）、
  `test_auth.py`（SSO 校验/缓存/fail closed），共 24 条用例

## v1.1.0（2026-09-02）— 服务器生产部署 + 百万数据性能与稳定性

### 部署
- 新服务器（腾讯云 4G / Ubuntu 24.04）全栈部署：业务 API + 后台 + 聊天界面 + RabbitMQ 审批通知
- 部署手册：`deploy/server-setup.md`（含国内 Docker 源/镜像加速/密钥上传/RAG 入库等全部坑）

### 性能（100 万订单数据）
- **订单搜索秒回**：`ILIKE '%kw%'` 百万级全表扫 → `pg_trgm` GIN 索引（30ms）
- **单号右模糊走 B-tree**：`text_pattern_ops` 索引（orders.order_no / refunds.refund_no），
  `LIKE 'CE2024%'` Index Scan 替代 trgm Bitmap；查询统一 `strtoupper` + 前缀匹配
- Api/Web 列表筛选（order_no / refund_no）全部支持模糊；非单号输入（客户名）保留 %kw% + trgm
- 详情/写操作保持精确匹配（防模糊命中多单破坏校验）

### 修复（部署级 bug，本地小数据测不出）
- **报表导出 worker 崩溃**：`exportOrders` 全量 `get()` 100w 订单 → PHP 内存耗尽 fatal
  （worker exit 255，任务卡 running）→ 改 `chunkById(2000)` 分批 + 文件句柄流式写 CSV
- **RAG 空库崩溃**：全新环境 Chroma 空 → `BM25Okapi` 除零 → 空库保护跳过 BM25 路
- **requirements 补依赖**：langgraph-checkpoint-postgres / psycopg / langchain-text-splitters
  （本地 .venv 有但清单漏,容器必缺）

### 功能
- `download_task` 返回**下载链接**（替代 CSV 全文贴聊天）：任务状态校验 + 对外 URL；
  GET 鉴权支持 `?token=` query（浏览器无 Authorization 头;写操作仍强制 header）

### Agent 稳定性（多轮对话）
- **上下文裁剪三连修**：
  1. 裁剪切散工具消息配对 → OpenAI 兼容 API 400（tool role 无前置 tool_calls）→ 回合边界对齐
  2. 历史摘要当独立 SystemMessage 塞消息列表 → LLM 复述乱回复 → 摘要并入唯一 system prompt
  3. 摘要 prompt 要求保留任务/金额/待办细节 → 摘要成复述素材 → **只概括 30 字主题,严禁细节**
- **重复提问被吞**（exists 跨轮误判）→ 答非所问 → 只判最后一条用户消息
- system 声明 [历史摘要]/[未处理事项] 为内部上下文,禁止向用户复述

### 前端
- 断线重连后 busy 卡死（发送无响应,刷新才恢复）→ onopen 复位状态
- 半开连接（标记 connected 实际断开）send 静默失败 → readyState 检查 + 强制重连

## v1.0.0（2026-09-01）— AI 聊天界面 + 容器化（见 README 里程碑 M5）
