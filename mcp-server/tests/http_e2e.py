# -*- coding: utf-8 -*-
"""HTTP 传输端到端测试: streamablehttp_client 连接 PHP MCP Server(无流模式)。"""
import asyncio, io, json, sys
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

from mcp import ClientSession
from mcp.client.streamable_http import streamablehttp_client

URL = "http://127.0.0.1:8081/message"

async def main() -> None:
    async with streamablehttp_client(URL) as (read, write, get_session_id):
        async with ClientSession(read, write) as session:
            # 1. initialize
            init = await session.initialize()
            print(f"✅ 握手: {init.serverInfo.name} v{init.serverInfo.version} protocol={init.protocolVersion}")

            # 2. tools/list
            tools = await session.list_tools()
            print(f"✅ tools/list: {len(tools.tools)} 个工具")
            names = [t.name for t in tools.tools]
            print("   工具:", ", ".join(names[:6]), "...")

            # 3. tools/call get_order
            res = await session.call_tool("get_order", {"order_no": "CE202409010028"})
            text = res.content[0].text if res.content else ""
            data = json.loads(text)
            print(f"✅ get_order: {data.get('order_no')} status={data.get('status_label')} paid={data.get('paid_amount')}")
            print(f"   isError={res.isError}")

            # 4. 错误路径: 缺参数
            res2 = await session.call_tool("get_order", {})
            print(f"✅ 缺参数 isError={res2.isError}, msg={res2.content[0].text[:40] if res2.content else ''}")

    print("\n✅ streamable HTTP 传输验证完成")

if __name__ == "__main__":
    asyncio.run(main())
