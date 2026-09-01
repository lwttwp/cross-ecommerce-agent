# -*- coding: utf-8 -*-
"""
LangGraph 连接 MCP Server 验证脚本。

验证点:
  1. 通过 MCP stdio 协议拉起 mcp-server/bin/mcp-server.php(真实进程)
  2. tools/list 加载为 LangChain 工具
  3. 直接调用一个工具(get_order)验证工具层转发
  4. 绑定 DeepSeek LLM 跑一轮真实对话(意图→工具调用→回答),验证"工具层可替换"

用法:
  .venv\Scripts\python mcp-server\tests\langgraph_connect.py
"""
import asyncio
import io
import os
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))   # agent/
MCP_DIR = os.path.join(BASE, "..", "mcp-server")
sys.path.insert(0, os.path.join(BASE, "src"))

from dotenv import load_dotenv  # noqa: E402
load_dotenv(os.path.join(BASE, ".env"))

from mcp import ClientSession, StdioServerParameters  # noqa: E402
from mcp.client.stdio import stdio_client  # noqa: E402
from langchain_mcp_adapters.tools import load_mcp_tools  # noqa: E402
from langchain_openai import ChatOpenAI  # noqa: E402
from langchain_core.messages import HumanMessage  # noqa: E402


def get_llm():
    return ChatOpenAI(
        model=os.getenv("DEEPSEEK_MODEL", "deepseek-chat"),
        api_key=os.getenv("DEEPSEEK_API_KEY"),
        base_url=os.getenv("DEEPSEEK_BASE_URL", "https://api.deepseek.com"),
        temperature=0,
    )


async def main() -> None:
    # ---------- 1. stdio 拉起 MCP Server ----------
    server_params = StdioServerParameters(
        command="php",
        args=["bin/mcp-server.php"],
        cwd=MCP_DIR,
    )
    async with stdio_client(server_params) as (read, write):
        async with ClientSession(read, write) as session:
            # 初始化握手
            init = await session.initialize()
            print(f"✅ MCP 握手: server={init.serverInfo.name} v{init.serverInfo.version} "
                  f"protocol={init.protocolVersion}")

            # ---------- 2. 工具加载 ----------
            tools = await load_mcp_tools(session)
            print(f"✅ 加载 MCP 工具 {len(tools)} 个: {[t.name for t in tools]}")

            # ---------- 3. 直接调用工具(不经 LLM) ----------
            get_order = next(t for t in tools if t.name == "get_order")
            result = await get_order.ainvoke({"order_no": "CE202608241027"})
            print(f"✅ get_order 直接调用: type={type(result).__name__} raw={str(result)[:200]}")
            import json as _json
            # langchain-mcp-adapters 把 content 解析成 list[dict{'type','text'}]
            if isinstance(result, list) and result:
                item = result[0]
                text = item['text'] if isinstance(item, dict) else item
                data = _json.loads(text) if isinstance(text, str) else text
            else:
                data = _json.loads(result) if isinstance(result, str) else result
            print(f"   → order={data.get('order_no')} status={data.get('status_label')} paid={data.get('paid_amount')}")

            # ---------- 4. LLM + MCP 工具,真实对话 ----------
            llm = get_llm().bind_tools(tools)
            print("\n🧑 用户: 查一下 CE202608241027 这个订单")
            resp = await llm.ainvoke([HumanMessage(content="查一下 CE202608241027 这个订单")])

            if resp.tool_calls:
                call = resp.tool_calls[0]
                print(f"🤖 LLM 选择工具: {call['name']}({call['args']})")
                tool = next(t for t in tools if t.name == call["name"])
                tool_result = await tool.ainvoke(call["args"])
                print(f"🔧 工具返回: {str(tool_result)[:120]}...")

                # 把工具结果喂回 LLM 生成最终回答
                from langchain_core.messages import ToolMessage
                final = await llm.ainvoke([
                    HumanMessage(content="查一下 CE202608241027 这个订单"),
                    resp,
                    ToolMessage(content=str(tool_result), tool_call_id=call["id"]),
                ])
                print(f"🤖 最终回答: {final.content[:200]}")
            else:
                print(f"🤖 LLM 直接回答(未调工具): {resp.content[:200]}")

    print("\n✅ LangGraph ↔ MCP Server 链路验证完成")


if __name__ == "__main__":
    asyncio.run(main())
