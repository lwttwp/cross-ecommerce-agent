# cross-ecommerce-agent

跨境电商智能订单助手 —— 用自然语言对话承接客服与运营日常操作的 Agent 系统。

**核心亮点**:LangGraph 多代理编排 + PHP 业务系统(真实数据模型/状态机/审批流)两层解耦,覆盖订单查询、物流跟踪、售后退款(人工审批)、政策问答(RAG)、数据报表(异步任务)五类能力;内置 Web 聊天界面,多会话、流式输出、退款确认、审批结果实时推送。

## 架构总览

```
客服/管理员 ──▶ 聊天界面(Web 原生 JS, 入口 /chat)
                    │  WebSocket 流式(打字机效果)
                    ▼
              Agent 服务(FastAPI + LangGraph + DeepSeek)
                    │  工具调用(工具层, 可替换)
                    ▼
             PHP MCP Server / REST + Token
                    │
                    ▼
             Laravel 业务系统(唯一事实来源)
             PostgreSQL + RabbitMQ(异步任务/审批事件)
                    │
                    ▼
        审批结果 ──▶ RabbitMQ ──▶ 聊天界面实时通知条
```

三层解耦:Agent 只负责对话与编排,不碰数据库;业务系统不感知 AI,所有写操作走业务校验;退款等资金操作强制 human-in-the-loop。聊天界面为独立服务(容器化),经 nginx `/chat` 统一入口,审批事件经 RabbitMQ 实时回推对应会话。

详细设计见 [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md),需求与验收见 [docs/PRD.md](docs/PRD.md)。

## 技术栈

| 层     | 技术                                              |
|-------|-------------------------------------------------|
| 业务系统  | PHP 8.3 + Laravel 12 + PostgreSQL 16            |
| 队列    | RabbitMQ + php-amqplib(异步报表、审批事件)               |
| Agent | Python + LangGraph / LangChain + DeepSeek API   |
| 聊天服务  | FastAPI + WebSocket(流式 / 多会话 / 审批推送)           |
| 前端    | 原生 HTML/CSS/JS(零框架零构建,单文件)                    |
| RAG   | Chroma + 百炼 embedding + 混合检索(向量 + BM25 + RRF)   |
| 部署    | Docker Compose(nginx → php-fpm → postgres + chat,端口错开) |

## 快速开始

前置:安装 Docker Desktop。

```bash
# 1. 一键启动(构建 + 启动,含聊天服务)
deploy\start-all.bat

# 2. 验证
curl http://127.0.0.1:8000/api/v1/health     # {"code":0} 即就绪
```

| 入口                        | 说明                          |
|---------------------------|-----------------------------|
| http://127.0.0.1:8000      | 业务后台(管理/审批)                |
| **http://127.0.0.1:8000/chat/** | **AI 聊天界面(nginx 反代统一入口)** |
| http://127.0.0.1:15673      | RabbitMQ 管理(ce_app / ce_app_2026) |

业务系统首次启动自动建表 + 造数(100 万订单演示数据)。

### 聊天界面功能

- **多会话**:右侧会话栏,新建/切换/历史恢复(会话数据落 PG checkpointer)
- **流式输出**:token 逐字打字 + 思考加载动画
- **退款确认**:大额退款弹确认卡片,human-in-the-loop
- **审批实时通知**:退款审批(通过/驳回)结果经 RabbitMQ 事件实时推送到会话窗口
- **上下文裁剪**:发给大模型的只保留最近 10 条原文 + 更早历史摘要 + 未决事项(待审批退款等)

### Agent 本地开发

```bash
cd agent
python -m venv .venv
.venv\Scripts\pip install -r requirements.txt
# 复制 .env.example 为 .env,填入 DEEPSEEK_API_KEY、业务 API Token 等
.venv\Scripts\python src\cross_ecommerce_agent\graph\build.py   # 跑一次完整退款流程(含中断)
.venv\Scripts\python chat_server.py                              # 本地起聊天服务(8082)
```

## 评测

```bash
cd eval
..\agent\.venv\Scripts\python run_eval.py              # 全量 34 条
..\agent\.venv\Scripts\python run_eval.py --category 政策问答
..\agent\.venv\Scripts\python run_eval.py --max 5 --json report.json
```

- 用例:6 类 34 条(订单查询/物流跟踪/退款售后/政策问答/报表任务/边界异常),当前通过率 **34/34 = 100%**
- 断言:关键词命中、禁止词、中断挂起、无异常
- `--retry` 参数:失败用例自动重试,区分 LLM 波动(flaky)与稳定 bug
- 正向退款用例只验证到确认中断,不实际提交,评测零副作用

## 里程碑

| 阶段                              | 状态     |
|---------------------------------|--------|
| M1 业务系统地基(数据模型/API/鉴权/造数)       | ✅      |
| M2 业务闭环(状态机/退款审批/队列报表)          | ✅      |
| M3 Agent 集成(路由/工具/interrupt/RAG/记忆) | ✅      |
| M4 工程化收尾(评测集/部署脚本/README/演示)    | ✅      |
| M5 聊天界面 + 容器化(多会话/流式/审批推送/反代)  | ✅      |

## 目录结构

```
cross-ecommerce-agent/
├── business-system/   # Laravel 业务系统(订单/商品/客户/退款/任务)
├── agent/
│   ├── src/cross_ecommerce_agent/   # LangGraph 应用(graph/rag/tools/llm)
│   ├── chat/                        # 聊天服务(chat_manager 会话/notify_bridge 审批推送/static 前端)
│   ├── chat_server.py               # FastAPI 入口(/ws 流式 + /api/sessions)
│   └── Dockerfile                   # 聊天服务容器镜像
├── docs/              # PRD.md / ARCHITECTURE.md
├── eval/              # 评测用例 + 评测脚本
└── deploy/            # 一键启动/停止/状态脚本
```
