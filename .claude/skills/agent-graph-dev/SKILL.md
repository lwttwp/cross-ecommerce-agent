---
name: agent-graph-dev
description: 在 agent/ 下开发时使用——加 LangGraph 节点、加专家 Agent、加业务工具、改 prompt、动上下文裁剪或流式输出。这个项目的 Agent 层有几个反直觉的硬约束（只能同步 stream 不能 astream、裁剪点必须对齐 tool_calls 回合边界、全部绝对导入、requirements.txt 才是依赖准绳），不知道就会重复踩坑。用户提到 LangGraph、节点、专家、supervisor、意图路由、interrupt、上下文裁剪、流式、RAG、prompt、DeepSeek 时用这个 skill。
---

# Agent 层开发

## 先纠正目录认知

CLAUDE.md 写「`agent/src/` 按职责分模块（nodes / tools / rag / config）」—— **与实况不符**。
真实包根是 `agent/src/cross_ecommerce_agent/`：

```
agent/src/cross_ecommerce_agent/
├── config.py          # 全部配置常量 + 4 段 prompt 模板（是单文件，不是 config/ 包）
├── refund_mapper.py   # refund_no → thread_id 映射（原子写 + 线程锁）
├── graph/
│   ├── build.py       # 建图 + make_checkpointer() + 编译
│   ├── state.py       # OverAllState(MessagesState)
│   ├── router.py      # rule_precheck + IntentRouter.classify + route_by_intent
│   ├── nodes.py       # 所有节点 + 上下文裁剪 + 退款 interrupt（最长文件）
│   └── agents.py      # 专家层：3 个 ReAct 子 Agent 包装成工具
├── llm/client.py      # get_llm(temperature=0.3) 单例，ChatDeepSeek 禁 thinking
├── rag/               # retriever / query_rewrite / ingest / embeddings / documents/
└── tools/business.py  # BusinessApiClient（httpx）+ 14 个 @tool
```

顶层 `agent/chat_server.py` 是服务入口（FastAPI + WebSocket），`agent/chat/` 是会话管理与通知桥接。

**几个文件是 0 字节空文件，别当它们存在**：
`agent/config/business_api.yaml`（废弃占位，无任何代码读取）、`agent/tests/test_router.py`、
`src/cross_ecommerce_agent/main.py`、`tools/mcp_client.py`、`scripts/ingest_docs.py`。

（`rag/ingest.py` 的 `__main__` 才是真正的入库逻辑，不是 `scripts/ingest_docs.py`。）

## 图拓扑

```
START → intent_router ─route_by_intent─┬─ policy → rag_node    → END
                                       ├─ refund → refund_node → END
                                       └─ query / report → supervisor
                                                              │
                                              tool_router ────┴──→ expert_tools
                                                              │        │
                                                             END       └→ supervisor（回环）
```

- 建图 `graph/build.py:27`，编译 `build.py:62`
- 意图四类 `policy | query | refund | report`（`graph/router.py`），先 `rule_precheck` 正则预检再 LLM 结构化分类
- 专家 `graph/agents.py`：`order_agent`（订单/物流/商品/客户，8 工具）、
  `aftersale_agent`（退款单查询，1 工具）、`report_agent`（报表/导出/下载，3 工具）
- ⚠️ `nodes.py:24-27` 的模块 docstring 描述的是**旧单 Agent 拓扑，已过时**，别被误导
- `agent_node` / `tool_node` 是保留未接线的旧回路（`build.py:31,33,59-60`）；
  `latest_refund`（`nodes.py:217`）、state 里的 `refund_status` 是未使用/未写入的遗留

## 新代码往哪放

| 要加什么 | 放哪 | 还要改什么 |
|---|---|---|
| 节点 | `graph/nodes.py` | `graph/build.py` 里 `add_node` + `add_edge` |
| 专家 Agent | `graph/agents.py` | 加 prompt + `create_react_agent` + 包进 `agent_tools`；工具分组在 `agents.py:21-24` |
| 业务工具 | `tools/business.py`（`@tool("name", parse_docstring=True)`） | 加入 `nodes.py:97-99` 的 `tools` 与 `agents.py:21-24` 分组；**并与 mcp-server 侧对齐** |
| prompt / 常量 | `config.py` | — |
| RAG 能力 | `rag/` | — |
| 运维/对比脚本 | `scripts/` | — |

**导入约定：全部绝对导入** —— `from cross_ecommerce_agent.<子包>.<模块> import ...`，
仓库里没有一处相对导入。入口脚本靠 `sys.path.insert(0, os.path.join(BASE_DIR, "src"))` 注入
（`chat_server.py:24-26`、`refund_consumer.py:21-22`、`chat/notify_bridge.py:23-24` 等）。

**依赖：新增包必须同步写 `agent/requirements.txt`。**
以 requirements.txt 为准 —— `pyproject.toml` 的 dependencies 已经落后于它。
本地 `.venv` 有 ≠ 容器有，这是本项目最常见的事故之一。

## 硬约束

### 1. 流式只能用同步 `graph.stream`，不能用 `astream`

`chat_server.py:64-74` 的 `run_graph_sync` 是**同步生成器**：
`graph.stream(input, config, stream_mode=["messages","values"])`；
再由 `chat_server.py:77-127` 用 `asyncio.Queue` + `loop.run_in_executor(None, producer)` 桥到异步 WS。

原因（`chat_server.py:66-69` 注释）：checkpointer 是同步 `PostgresSaver`，
异步 API 会抛 `NotImplementedError`（`aget_tuple` 未实现）。

> `chat_server.py:69` 的 docstring 写的是 `asyncio.to_thread`，但实现用的是 `run_in_executor` ——
> 仓库里没有真正的 `to_thread` 调用。**以代码为准。**

帧处理约定：`messages` 模式只流 AI 正文（跳过 `tool_calls`），
`values` 模式检测 `__interrupt__` 与 `answer`。

### 2. 上下文裁剪点必须对齐 AI(`tool_calls`) / ToolMessage 回合边界

`graph/nodes.py:29-65` 的 `build_context(state) -> (recent, extra_text)`：

- `HISTORY_RECENT_KEEP = 10`（`config.py:109`），只保留最近 10 条原文，其余丢给摘要
- `nodes.py:44-47`：若 `recent[0]` 是 `ToolMessage`，从 `old` 尾部补回对应的 `AI(tool_calls)`
- `nodes.py:48-51`：若 `recent[-1]` 是带 `tool_calls` 的孤立 `AIMessage`（其 ToolMessage 还没产生），丢回 `old`
- **不对齐的后果**：tool role 消息没有前置 `tool_calls` → OpenAI 兼容 API 直接 400

摘要还有第二个坑：摘要**不能作为独立 `SystemMessage` 插进消息列表**（LLM 会把它当回复内容复述），
要经 `extra_text` 合并进唯一 system prompt。摘要 prompt 要求「30 字内概括主题，严禁泄漏单号/金额」
（`config.py:110-117`）—— 摘要是复述素材，细节留多了 agent 就会照着念。

### 3. 退款 interrupt 后不恢复图

第一段 interrupt 挂起（`nodes.py:272-279`），第二段管理员审批**不挂起图** ——
审批结果经 RabbitMQ 异步推回。见 `refund-flow` skill。

## 验证

```bash
python eval/run_eval.py --category 政策问答      # 改 prompt / RAG 后先跑单类，快
python eval/run_eval.py --retry 1               # 最终全量 37 条，必须全过
python mcp-server/tests/langgraph_connect.py    # 改工具层后验链路
```

改检索可以人工对比效果：`agent/scripts/compare_retrieval.py`（纯向量 vs 混合）、
`agent/scripts/compare_rewrite.py`（直检 vs rewrite vs multi-query）。

**空库保护不能删**：全新环境 Chroma 为空会让 `BM25Okapi` 除零崩溃。
`rag/retriever.py` 的 `HybridRetriever` 已有空库保护跳过 BM25 路，改检索逻辑时保留它。
