# Changelog

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
