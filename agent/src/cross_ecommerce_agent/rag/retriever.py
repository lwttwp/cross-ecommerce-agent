from langchain_chroma import Chroma
from langchain_core.documents import Document
import jieba
from rank_bm25 import BM25Okapi
import cross_ecommerce_agent.config as config
from cross_ecommerce_agent.rag.embeddings import OpenAICompatibleEmbeddings


def _tokenize(text: str) -> list[str]:
    """中文用 jieba 分词,英文按词保留(BM25 需要 token 列表)。"""
    return [t.strip() for t in jieba.cut(text) if t.strip()]


def _doc_access_level(doc: Document) -> str:
    """chunk 的权限级别；缺失/非法值 fail closed 到默认级别。"""
    level = (doc.metadata or {}).get('access_level', config.RAG_ACCESS_DEFAULT)
    return level if level in ('agent', 'admin') else config.RAG_ACCESS_DEFAULT


def _allowed_levels(role: str | None) -> tuple[str, ...]:
    return config.RAG_ACCESS_LEVELS.get(
        role or config.RAG_DEFAULT_ROLE,
        config.RAG_ACCESS_LEVELS[config.RAG_DEFAULT_ROLE],
    )


def _access_filter(allowed: tuple[str, ...]) -> dict:
    """Chroma metadata filter：单级别用等值，多级别用 $or（兼容老版本 Chroma）。"""
    if len(allowed) == 1:
        return {'access_level': allowed[0]}
    return {'$or': [{'access_level': level} for level in allowed]}


class VectorStoreService:
    """纯向量检索服务(Chroma)。"""

    def __init__(self):
        self.vector_store = Chroma(
            collection_name=config.CHROMA_COLLECTION,
            embedding_function=OpenAICompatibleEmbeddings(
                model=config.EMBEDDING_MODEL,
                api_key=config.EMBEDDING_API_KEY,
                base_url=config.EMBEDDING_BASE_URL
            ),
            persist_directory=config.CHROMA_PERSIST_DIR,
        )

    def get_retriever(self, k: int = config.RETRIEVE_K, role: str | None = None):
        return self.vector_store.as_retriever(
            search_kwargs={"k": k, "filter": _access_filter(_allowed_levels(role))}
        )


class HybridRetriever:
    """混合检索:向量(Chroma) + 关键词(BM25/jieba),RRF 融合排序。

    - 向量路:语义相似,召回"说法不同但意思相近"的内容
    - BM25 路:精确关键词命中,召回"专有名词/编号/数字"类内容
    - RRF(Reciprocal Rank Fusion):对两路排名做倒数融合,抵消量纲差异

    注意:入库(ingest.py)后 BM25 索引是旧的,调用 rebuild_index() 刷新。
    """

    def __init__(self,
                 vector_k: int = config.VECTOR_TOP_K,
                 bm25_k: int = config.BM25_TOP_K,
                 rrf_k: int = config.RRF_K,
                 store=None):
        # 传入 store 时与入库服务共享同一个 Chroma client，避免多客户端写锁
        self._store = store or VectorStoreService().vector_store
        self._vector_k = vector_k
        self._bm25_k = bm25_k
        self._rrf_k = rrf_k
        self._bm25: BM25Okapi | None = None
        self._corpus: list[Document] = []
        # 角色 -> 该角色可见的语料/BM25 索引（权限过滤在检索层完成）
        self._corpus_by_role: dict[str, list[Document]] = {}
        self._bm25_by_role: dict[str, BM25Okapi | None] = {}
        self.rebuild_index()

    def rebuild_index(self):
        """从 Chroma 全量拉取 chunks,重建 BM25 索引(与向量库 chunk 对齐)。"""
        data = self._store._collection.get(include=["documents", "metadatas"])
        self._corpus = [
            Document(page_content=content, metadata=meta or {}, id=doc_id)
            for doc_id, content, meta in zip(data["ids"], data["documents"], data["metadatas"])
        ]
        if not self._corpus:
            # 空库(全新环境未入库):不构建 BM25,避免 rank_bm25 除零;入库后 rebuild 即可
            self._bm25 = None
            self._corpus_by_role = {}
            self._bm25_by_role = {}
            print("[retriever] 向量库为空,BM25 索引暂不构建(请先运行 rag/ingest.py 入库)")
            return
        self._bm25 = BM25Okapi([_tokenize(d.page_content) for d in self._corpus])
        self._corpus_by_role = {}
        self._bm25_by_role = {}
        for role, levels in config.RAG_ACCESS_LEVELS.items():
            docs = [d for d in self._corpus if _doc_access_level(d) in levels]
            self._corpus_by_role[role] = docs
            self._bm25_by_role[role] = (
                BM25Okapi([_tokenize(d.page_content) for d in docs]) if docs else None
            )

    def invoke(self, query: str, k: int | None = None, role: str | None = None) -> list[Document]:
        """混合检索，按角色过滤授权级别后返回融合 top-k。"""
        k = k or self._vector_k
        role_key = role if role in config.RAG_ACCESS_LEVELS else config.RAG_DEFAULT_ROLE
        allowed = config.RAG_ACCESS_LEVELS[role_key]
        if not self._corpus_by_role:
            self.rebuild_index()

        # 向量路: Chroma metadata filter + 本地二次校验（双保险）
        vec_results = self._store.similarity_search_with_score(
            query, k=self._vector_k, filter=_access_filter(allowed)
        )
        vec_results = [(d, s) for d, s in vec_results if _doc_access_level(d) in allowed]

        # BM25 路: 只用该角色的语料/索引，避免关键词路绕过权限
        corpus = self._corpus_by_role.get(role_key, [])
        bm25 = self._bm25_by_role.get(role_key)
        bm25_ranked: list[int] = []
        if bm25 is not None and corpus:
            bm25_scores = bm25.get_scores(_tokenize(query))
            bm25_ranked = sorted(range(len(bm25_scores)),
                                 key=lambda i: bm25_scores[i], reverse=True)[:self._bm25_k]

        # RRF 融合:score = sum(1 / (rrf_k + rank)),rank 从 1 起
        fused: dict[str, float] = {}
        doc_by_id: dict[str, Document] = {}

        for rank, (doc, _) in enumerate(vec_results, start=1):
            did = doc.id or doc.page_content
            doc_by_id[did] = doc
            fused[did] = fused.get(did, 0.0) + 1.0 / (self._rrf_k + rank)

        for rank, idx in enumerate(bm25_ranked, start=1):
            doc = corpus[idx]
            did = doc.id or doc.page_content
            doc_by_id[did] = doc
            fused[did] = fused.get(did, 0.0) + 1.0 / (self._rrf_k + rank)

        top_ids = sorted(fused, key=fused.get, reverse=True)[:k]
        return [doc_by_id[i] for i in top_ids]


if __name__ == '__main__':
    query = "DHL 快递要几天能到"
    print(f"查询: {query}\n")
    # print("=== 纯向量检索 ===")
    # vec = VectorStoreService().get_retriever().invoke(query)
    # for d in vec:
    #     print(f"- [{d.metadata.get('source')}] {d.page_content}")

    # print("\n=== 混合检索 ===")
    hybrid = HybridRetriever()
    # for d in hybrid.invoke(query):
    #     print(f"- [{d.metadata.get('source')}] {d.page_content}")

    # 查询改写
    from cross_ecommerce_agent.rag.query_rewrite import QueryRewriter

    rq = QueryRewriter()
    query = rq.multi_rewrite(query)
    seen, merged = set(), []
    for q in query:
        for res in hybrid.invoke(q):
            key = res.page_content
            if key not in seen:
                seen.add(key)
                merged.append(res)

    print(merged)
