# -*- coding: utf-8 -*-
"""Query Rewrite 端到端对比:直接混合检索 vs 改写后混合检索"""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

from cross_ecommerce_agent.rag.retriever import HybridRetriever
from cross_ecommerce_agent.rag.query_rewrite import QueryRewriter

QUERIES = [
    "那个鞋子磨脚不舒服想退掉",
    "DHL 几天能到啊",
    "礼品卡买了不想要能退钱吗",
    "运费能不能报销",
]

hybrid = HybridRetriever()
rw = QueryRewriter()

for q in QUERIES:
    print(f"\n{'=' * 60}")
    print(f"原始问题: {q}")
    print("=" * 60)

    print("\n-- 直接混合检索 top3 --")
    for d in hybrid.invoke(q):
        print(f"  [{d.metadata.get('source')}] {d.page_content[:40]}")

    rq = rw.rewrite(q)
    print(f"\n-- 改写后 [{rq}] 混合检索 top3 --")
    for d in hybrid.invoke(rq):
        print(f"  [{d.metadata.get('source')}] {d.page_content[:40]}")

    print("\n-- Multi-Query 合并检索 top3 --")
    seen, merged = set(), []
    for mq in rw.multi_rewrite(q):
        for d in hybrid.invoke(mq):
            key = d.page_content
            if key not in seen:
                seen.add(key)
                merged.append(d)
    for d in merged[:3]:
        print(f"  [{d.metadata.get('source')}] {d.page_content[:40]}")
