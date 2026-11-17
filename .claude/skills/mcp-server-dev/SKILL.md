---
name: mcp-server-dev
description: 改动 mcp-server/（原生 PHP 手写 MCP Server，JSON-RPC 2.0 + stdio）时使用——加业务工具、改协议分发、改 stdio 传输、跑 MCP 测试。这里的工具注册点只有一处但「14 个工具」这个数字被硬编码在三个地方，加工具不同步改就会让冒烟测试变红。用户提到 MCP、JSON-RPC、tools/list、tools/call、stdio、工具注册、BusinessTools 时用这个 skill。
---

# MCP Server 开发

零依赖的原生 PHP 实现（**不用 composer autoload** —— 入口脚本手写 `require`，
`composer.json` 的 PSR-4 映射实际未生效）。

```
mcp-server/
├── bin/mcp-server.php      # stdio 入口：装配 server + 主循环
├── bin/http_router.php     # streamable-HTTP 入口（php -S 单文件路由）
├── src/
│   ├── Env.php             # 极简 .env 解析
│   ├── BusinessApiClient.php  # 业务 REST 客户端（curl，Bearer token）
│   ├── ToolRegistry.php    # 注册表：register / all / call + 必填参数校验
│   ├── McpServer.php       # JSON-RPC 2.0 分发核心
│   └── Tools/BusinessTools.php  # 14 个业务工具声明
└── tests/
```

## 协议分发

`src/McpServer.php`：

- `handle(?array $msg): ?array`（`:33-69`）—— JSON 解析失败 `-32700`；
  校验 `jsonrpc==2.0` 与 `method` 为字符串，否则 `-32600`；
  用 `match ($msg['method'])` 分发（`:50-59`）；通知（无 `id`）返回 `null`
- 方法表：`initialize` / `notifications/initialized` / `ping` / `tools/list` / `tools/call`；
  `resources/list` 与 `prompts/list` 返回空数组；未知方法抛 `RuntimeException(..., -32601)`
- 协议版本常量 `2025-03-26`（`:18`）
- 参数校验在 `src/ToolRegistry.php:66-75` —— 按 `inputSchema['required']` 通用拦截，
  缺参数返回 `isError=true`，不是 JSON-RPC error

## 加一个业务工具

**只需改一个文件**：`src/Tools/BusinessTools.php` 的 `BusinessTools::build()` 返回数组
（`:21-216`）里追加一条：

```php
[
    'name'        => 'tool_name',
    'description' => '...',
    'inputSchema' => [
        'type'       => 'object',
        'properties' => ['x' => $str('说明')],
        'required'   => ['x'],
    ],
    'handler'     => static fn(array $a) => $api->get('/path', $a),
]
```

`$str()` / `$int()` 是 `:18-19` 的辅助闭包，生成 `{type, description}`。

**不用改** `McpServer.php` 和 `ToolRegistry.php` —— 分发是通用 `match`，
两个入口（`bin/mcp-server.php:40-42`、`bin/http_router.php:45-47`）都是
`foreach (BusinessTools::build())` 自动注册。

### ⚠️ 工具数量 14 硬编码在三处

加（或删）工具后**必须同步**，否则冒烟测试变红：

- `tests/client_smoke.php:83` —— 断言 `count($tools) === 14`
- `tests/stdio_e2e.php:84` —— 同上
- `README.md:69` —— 文档里的工具清单

### 与 agent 侧对齐

`BusinessTools.php:11` 注释声明这 14 个工具与 `agent/src/cross_ecommerce_agent/tools/business.py`
的 14 个 LangChain 工具**一一对应**。两边改一边会静默不一致。

## stdio 传输（Windows 的坑）

- **服务端主循环 `bin/mcp-server.php:52` 就是普通阻塞 `fgets(STDIN)`** —— 没有非阻塞改造。
  换行分隔 JSON，无 `Content-Length` 头；响应写 STDOUT 并 `fflush`。
- **CLAUDE.md 说的「MCP stdio 不能阻塞读」，落点其实在测试端**：
  `tests/stdio_e2e.php:29-48` 的 `$readLine`。它不靠 `stream_set_timeout` 让 `fgets` 超时
  （Windows 上对 `fgets` 不生效），而是用**截止时间循环** + 读
  `stream_get_meta_data($pipe)['timed_out']`，把「超时」和「EOF」区分开：
  超时 `continue` 继续等，否则才 `return null` 表示 EOF。
  改动 stdio 测试时别把这个区分去掉 —— 否则会随机把慢响应误判成断开。

## 测试

| 测试 | 覆盖 | 跑法 |
|---|---|---|
| `tests/client_smoke.php` | 单元冒烟，**不走传输**，直接 `$server->handle()`。握手/协议版本、通知无响应、ping、tools/list、get_order 真调用、缺参 isError、未知方法 -32601、非法 JSON -32700 | `php mcp-server/tests/client_smoke.php` |
| `tests/stdio_e2e.php` | 端到端真实进程管道（`proc_open` 拉起 `bin/mcp-server.php`） | `php mcp-server/tests/stdio_e2e.php` |
| `tests/http_e2e.py` | streamable HTTP | 先 `php -S 127.0.0.1:8081 bin/http_router.php`，再 `python tests/http_e2e.py` |
| `tests/langgraph_connect.py` | LangGraph ↔ MCP 全链路（需 `DEEPSEEK_API_KEY`） | `python mcp-server/tests/langgraph_connect.py` |

前两个有断言和退出码判定（末尾 `全部通过 ✅` / `失败 N 项 ❌`）。
后两个**没有断言，纯人工判读 `✅` 行**。

前三个都依赖业务 API 真实可达 —— 先确认 `curl http://127.0.0.1:8000/api/v1/health` 返回 `{"code":0}`。

改完按 `verify-change` skill 的阶梯走。
