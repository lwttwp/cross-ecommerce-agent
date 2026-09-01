# -*- coding: utf-8 -*-
"""混合检索 vs 纯向量检索 对比测试"""
from cross_ecommerce_agent.rag.retriever import VectorStoreService, HybridRetriever

QUERIES = [
    ("语义型", "鞋子磨脚不舒服想退货怎么办"),
    ("精确型", "DHL 快递要几天能到"),
    ("精确型", "条款 4.2 说退款要多久"),
    ("混合型", "礼品卡 25 美元能退吗"),
    ("混合型", "积分怎么兑换优惠券"),
]

vec = VectorStoreService().get_retriever()
hybrid = HybridRetriever()

for kind, q in QUERIES:
    print(f"\n{'=' * 60}")
    print(f"[{kind}] 查询: {q}")
    print("=" * 60)

    vec_docs = vec.invoke(q)
    hyb_docs = hybrid.invoke(q)

    print("\n-- 纯向量 top3 --")
    for d in vec_docs:
        src = d.metadata.get('source', '?')
        print(f"  [{src}] {d.page_content[:42]}")

    print("\n-- 混合 top3 --")
    for d in hyb_docs:
        src = d.metadata.get('source', '?')
        print(f"  [{src}] {d.page_content[:42]}")
