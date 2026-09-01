# -*- coding: utf-8 -*-
"""
Agent 全链路走 MCP 工具层(演示版)。

用 LangGraph create_react_agent + langchain-mcp-adapters:
  整个 Agent 的工具层来自 PHP MCP Server(stdio 协议),不 import tools/business.py。
验证"工具层可替换": 换掉业务工具来源,Agent 行为不变。

用法:
  .venv\Scripts\python mcp_agent.py
"""
import asyncio
import io
import os
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))  # agent/
sys.path.insert(0, os.path.join(BASE_DIR, "src"))
MCP_DIR = os.path.join(BASE_DIR, "..", "mcp-server")

from dotenv import load_dotenv  # noqa: E402
load_dotenv(os.path.join(BASE_DIR, ".env"))

from langchain_mcp_adapters.client import MultiServerMCPClient  # noqa: E402
from langchain_openai import ChatOpenAI  # noqa: E402
from langgraph.prebuilt import create_react_agent  # noqa: E402
from langchain_core.messages import HumanMessage, AIMessage  # noqa: E402


def get_llm():
    api_key = os.getenv("DEEPSEEK_API_KEY")
    return ChatOpenAI(
        model=os.getenv("DEEPSEEK_MODEL", "deepseek-chat"),
        api_key=api_key,
        base_url=os.getenv("DEEPSEEK_BASE_URL", "https://api.deepseek.com"),
        temperature=0,
    )


async def main() -> None:
    # ---------- 1. MCP 工具层(stdio 拉起 PHP MCP Server) ----------
    client = MultiServerMCPClient({
        "cross-ecommerce": {
            "command": "php",
            "args": ["bin/mcp-server.php"],
            "transport": "stdio",
            "cwd": MCP_DIR,
        }
    })
    tools = await client.get_tools()
    print(f"✅ MCP 工具层加载 {len(tools)} 个工具(来自 PHP MCP Server)")

    # ---------- 2. ReAct Agent ----------
    agent = create_react_agent(get_llm(), tools)
    history = []

    async def chat(text: str) -> None:
        nonlocal history
        print(f"\n🧑 用户: {text}")
        history.append(("human", text))
        result = await agent.ainvoke({"messages": history})
        for msg in result["messages"][len(history) - 1:]:
            role = type(msg).__name__
            if role in ("HumanMessage", "AIMessage") and msg.content:
                print(f"🤖 {msg.content[:300]}")
            elif role == "ToolMessage":
                print(f"🔧 工具[{msg.name}]: {str(msg.content)[:120]}")
        history = [("human", m.content) if isinstance(m, HumanMessage) else ("ai", m.content)
                   for m in result["messages"]
                   if isinstance(m, (HumanMessage, AIMessage)) and m.content]

    # ---------- 3. 演示对话(多轮) ----------
    await chat("查一下 CE202608241027 这个订单")
    await chat("这个订单到哪了")
    await chat("帮我申请一下 CE202608241027 的退款，理由是不想要了")

    await client.__aexit__(None, None, None)
    print("\n✅ Agent 全链路 MCP 演示完成")


if __name__ == "__main__":
    asyncio.run(main())
