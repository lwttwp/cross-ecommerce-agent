# Agent 层（M3）— Python / LangGraph

跨境电商智能订单助手的 **Agent 编排层**：对话、意图路由、工具调用、RAG、human-in-the-loop 审批。

## 目录结构

```
agent/
├── src/cross_ecommerce_agent/
│   ├── config.py          # 配置加载（.env）
│   ├── main.py            # 入口：交互式对话 / HTTP 服务
│   ├── graph/             # LangGraph 编排
│   │   ├── state.py       # 图状态定义
│   │   ├── router.py      # 意图路由（query/operation/policy/report）
│   │   ├── nodes.py       # 各节点（工具调用/interrupt/RAG/兜底）
│   │   └── build.py       # 图组装
│   ├── tools/
│   │   ├── business.py    # 业务工具（REST 调 PHP 业务系统，Token 鉴权）
│   │   └── mcp_client.py  # MCP 客户端（对接 PHP MCP Server）
│   ├── rag/
│   │   ├── ingest.py      # 文档分块 → Ollama 向量化 → Chroma
│   │   ├── retriever.py   # Top-K 检索 + 重排
│   │   └── documents/     # 售后政策文档（Markdown）
│   └── llm/
│       └── client.py      # DeepSeek 封装
├── scripts/ingest_docs.py # RAG 文档入库脚本
├── tests/                 # 路由/工具单测
└── requirements.txt
```

## 技术栈

- **编排**：LangGraph（多代理图、interrupt 人工审批）
- **LLM**：DeepSeek API（OpenAI 兼容，`langchain-openai`）
- **Embedding**：本地 Ollama `qwen3-embedding:4b`（2560 维）
- **向量库**：Chroma（持久化 `data/chroma`）
- **工具协议**：MCP（客户端已装 `mcp` SDK，服务端为 PHP 原生实现）
- **业务调用**：REST + Bearer Token（`BIZ_API_TOKEN_AGENT/ADMIN`）

## 快速开始

```bash
cd agent
python -m venv .venv
.venv\Scripts\activate          # Windows
pip install -r requirements.txt
cp .env.example .env            # 填入 DEEPSEEK_API_KEY 等
python scripts/ingest_docs.py   # RAG 文档入库
python -m cross_ecommerce_agent.main   # 启动交互式对话
```

## 环境依赖

- 本机 Ollama（`http://127.0.0.1:11434`，需 `qwen3-embedding:4b`）
- 业务系统 PHP API（`http://127.0.0.1:8000/api/v1`）
- DeepSeek API Key
