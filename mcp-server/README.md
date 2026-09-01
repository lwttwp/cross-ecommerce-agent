# MCP Server(原生 PHP)

用原生 PHP 8.3 实现的 [Model Context Protocol](https://modelcontextprotocol.io) Server,零第三方依赖,
将业务系统(REST + Token)包装为标准 MCP 工具,供 LangGraph/LangChain 等客户端通过 stdio 调用。

差异化亮点:PHP 生态中 MCP 实现稀缺,本目录手写 JSON-RPC 2.0 分发 + stdio 传输,展示协议层功底。

## 目录结构

```
mcp-server/
├── bin/
│   └── mcp-server.php        # 入口:stdio 主循环
├── src/
│   ├── Env.php               # 极简 .env 解析(零依赖)
│   ├── BusinessApiClient.php # 业务 API REST 客户端(curl)
│   ├── ToolRegistry.php      # 工具注册/参数校验/执行
│   ├── McpServer.php         # JSON-RPC 2.0 分发核心
│   └── Tools/
│       └── BusinessTools.php # 14 个业务工具定义
├── tests/
│   ├── client_smoke.php      # 单元冒烟(直接调 handle)
│   └── stdio_e2e.php         # 端到端(真实进程管道通信)
├── .env.example
└── README.md
```

## 配置

```bash
cd mcp-server
copy .env.example .env
# 填入 BIZ_API_BASE 与 BIZ_API_TOKEN_AGENT(取 business-system/.env 的 API_TOKEN_AGENT)
```

## 运行

**stdio(本地进程,默认):**

```bash
php bin/mcp-server.php
# 监听 stdin,每行一个 JSON-RPC 消息(换行分隔,无 Content-Length 头)
```

**streamable HTTP(远程部署):**

```bash
php -S 127.0.0.1:8081 bin/http_router.php
# POST /message → JSON-RPC(无流模式);GET /sse → 通知流;GET /health
# 客户端: mcp SDK streamablehttp_client("http://127.0.0.1:8081/message")
```

## 测试

```bash
php tests/client_smoke.php   # 单元级:协议握手/工具列表/参数校验/错误码
php tests/stdio_e2e.php      # stdio 端到端:真实进程管道通信 + 真实业务调用
python tests/http_e2e.py     # HTTP 端到端:streamable HTTP 传输 + 真实业务调用
python tests/langgraph_connect.py  # LangGraph 经 stdio 拉起 MCP Server 全链路
```

## 协议实现(MCP 2025-03-26)

| 方法 | 说明 |
|---|---|
| `initialize` | 协议协商,返回 `protocolVersion` + `capabilities.tools` + `serverInfo` |
| `notifications/initialized` | 客户端就绪通知(无响应) |
| `ping` | 心跳 |
| `tools/list` | 返回 14 个工具(名称/描述/JSON Schema) |
| `tools/call` | 执行工具;参数缺失/业务错误返回 `isError=true` + 可读信息 |
| `resources/list` / `prompts/list` | 预留,返回空 |

错误码(JSON-RPC):`-32700` 解析错误、`-32600` 无效请求、`-32601` 方法不存在、`-32602` 参数错误、`-32603` 内部错误。

## 工具清单(14 个,与 agent/tools/business.py 对齐)

订单:`query_orders` `get_order` `create_order` `update_order_address` `cancel_order` `get_tracking`
商品:`query_products` `get_product` `query_customer`
退款:`apply_refund` `query_refunds`
任务:`create_task` `get_task` `download_task`

安全设计:
- 必填参数缺失在工具层直接拦截,非法请求不进入业务系统
- 写操作(create_order/apply_refund 等)保持业务系统既有校验(状态机/审批),Agent 无特权
- 业务错误(如"订单不存在")以 `isError=true` 返回,LLM 可读原因,不抛协议异常
