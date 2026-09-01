# cross-ecommerce-agent

跨境电商智能订单助手 —— 用自然语言对话承接客服与运营日常操作的 Agent 系统。

**核心亮点**:LangGraph 多代理编排 + PHP 业务系统(真实数据模型/状态机/审批流)两层解耦,覆盖订单查询、物流跟踪、售后退款(人工审批)、政策问答(RAG)、数据报表(异步任务)五类能力。

## 架构总览

```
客服/管理员 ──自然语言──▶ Agent(LangGraph + DeepSeek)
                            │  工具调用(工具层)
                            ▼
                     PHP MCP Server / REST + Token
                            │
                            ▼
                     Laravel 业务系统(唯一事实来源)
                     PostgreSQL + RabbitMQ(异步任务/审批事件)
```

三层解耦:Agent 只负责对话与编排,不碰数据库;业务系统不感知 AI,所有写操作走业务校验;退款等资金操作强制 human-in-the-loop。

详细设计见 [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md),需求与验收见 [docs/PRD.md](docs/PRD.md)。

## 技术栈

| 层     | 技术                                              |
|-------|-------------------------------------------------|
| 业务系统  | PHP 8.3 + Laravel 12 + PostgreSQL 16            |
| 队列    | RabbitMQ + php-amqplib(异步报表、审批事件)               |
| Agent | Python + LangGraph / LangChain + DeepSeek API   |
| RAG   | Chroma + 百炼 embedding + 混合检索(向量 + BM25 + RRF)   |
| 部署    | Docker Compose(nginx → php-fpm → postgres,端口错开) |

## 快速开始

前置:安装 Docker Desktop。

```bash
# 1. 一键启动(构建 + 启动 + 健康检查)
deploy\start-all.bat

# 2. 验证
curl http://127.0.0.1:8000/api/v1/health     # {"code":0} 即就绪

# 3. 常用命令
deploy\status.bat     # 容器状态 + 健康检查
deploy\stop-all.bat   # 停止(保留数据)
```

业务系统首次启动自动建表 + 造数(100 万订单演示数据)。

### Agent 环境

```bash
cd agent
python -m venv .venv
.venv\Scripts\pip install -r requirements.txt
# 复制 .env.example 为 .env,填入 DEEPSEEK_API_KEY、业务 API Token 等
.venv\Scripts\python src\cross_ecommerce_agent\graph\build.py   # 跑一次完整退款流程(含中断)
```

## 评测

```bash
cd eval
..\agent\.venv\Scripts\python run_eval.py              # 全量 34 条
..\agent\.venv\Scripts\python run_eval.py --category 政策问答
..\agent\.venv\Scripts\python run_eval.py --max 5 --json report.json
```

- 用例:6 类 34 条(订单查询/物流跟踪/退款售后/政策问答/报表任务/边界异常)
- 断言:关键词命中、禁止词、中断挂起、无异常
- 正向退款用例只验证到确认中断,不实际提交,评测零副作用
- 目标:整体通过率 ≥ 90%,负向用例 100% 拦截

## 里程碑

| 阶段                                  | 状态     |
|-------------------------------------|--------|
| M1 业务系统地基(数据模型/API/鉴权/造数)           | ✅      |
| M2 业务闭环(状态机/退款审批/队列报表)              | ✅      |
| M3 Agent 集成(路由/工具/interrupt/RAG/记忆) | ✅      |
| M4 工程化收尾(评测集/部署脚本/README/演示)        | 🔄 进行中 |

## 目录结构

```
cross-ecommerce-agent/
├── business-system/   # Laravel 业务系统(订单/商品/客户/退款/任务)
├── agent/             # LangGraph 应用(graph/rag/tools/llm)
├── docs/              # PRD.md / ARCHITECTURE.md
├── eval/              # 评测用例 + 评测脚本
└── deploy/            # 一键启动/停止/状态脚本
```
