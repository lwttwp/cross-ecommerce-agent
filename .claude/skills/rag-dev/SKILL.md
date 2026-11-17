---
name: rag-dev
description: 改 agent/src/cross_ecommerce_agent/rag/ 下任何东西时使用——加删知识库文档、重建索引、改切分策略、改 embedding、改混合检索或查询重写。这块有三个不报错但会让检索悄悄变差的陷阱（百炼 embedding 单批上限 10、空库让 BM25 除零、文档改内容后增量入库会留下过期 chunk），还有一套「改完怎么证明变好了」的对比脚本。用户提到 RAG、知识库、Chroma、向量库、embedding、BM25、混合检索、RRF、切分、chunk、重新入库、召回、查询重写、政策问答答错、检索不准时用这个 skill。
---

# RAG 检索层开发

## 先纠正三个目录认知

1. **真正的入库入口是 `rag/ingest.py` 的 `__main__`**，不是 `agent/scripts/ingest_docs.py`。
   后者是历史遗留脚本，改了它不会生效。
2. **文档源在 `rag/documents/*.md`（当前 15 篇）**，不是 `agent/data/`。
   `agent/data/chroma` 只是向量库的落盘目录，删了等于清库。
3. **`rag/test.txt` 是临时调试文件**，不要当知识库文档入库，也不要删掉别人的手测素材。

## 模块分工

| 文件 | 职责 |
|---|---|
| `rag/ingest.py` | 切分 + 入库，主入口 |
| `rag/embeddings.py` | OpenAI 兼容 embedding 适配器（`OpenAICompatibleEmbeddings`） |
| `rag/retriever.py` | 纯向量检索 + `HybridRetriever`（向量 + BM25 + RRF） |
| `rag/query_rewrite.py` | 查询重写，有独立 `__main__` 可单测 |
| `rag/documents/` | 知识库源文件（Markdown） |

配置全部来自 `config.py`：`CHROMA_PERSIST_DIR` / `CHROMA_COLLECTION` / `EMBEDDING_MODEL` / `EMBEDDING_API_KEY` / `EMBEDDING_BASE_URL`。
**不要在 rag/ 里直接读环境变量**，一律走 config。

---

## 硬约束（违反不会报错，只会悄悄变差）

### 1. embedding 单批最多 10 条

百炼的 embedding 端点单次请求上限是 10 条，超了直接 400。

`embeddings.py` 里的 `BATCH_SIZE = 10` 和分批循环**不能删，也不能改大**。
要加批量并发，只能并行发多个「每批 10 条」的请求，不能把批次调大。

> 顺带一个坑：**不要换回 `langchain_openai` 自带的 embeddings**。
> 它内部会先把文本转成 tiktoken 的 token id 再发请求，
> 百炼这类兼容端点只认原始字符串，会 400。
> 这就是 `OpenAICompatibleEmbeddings` 存在的唯一原因——直接用 openai SDK 发原始文本。

### 2. 空库保护不能删

全新环境里 Chroma 是空的，`BM25Okapi` 在空语料上会**除零崩溃**。

`retriever.py` 的 `HybridRetriever` 已经做了「空库跳过 BM25 路」的保护。
改检索逻辑、重构打分、换融合算法时，**这段保护必须保留**。
新增的检索路径同样要有空集分支，否则新环境第一跑就挂。

### 3. 文档「改内容」必须重建，增量入库会留脏 chunk

`ingest.py` 是增量追加（`add_documents` + 指定 ids）。
文档**新增**用它没问题；但**修改已有文档**时，旧的 chunk 不会自动消失，
检索时会把过期段落一起召回，表现为「答的还是老政策」。

判断规则：

| 动作 | 做法 |
|---|---|
| 新增一篇文档 | 增量入库即可 |
| 修改/删除已有段落 | **重建集合** |

重建之前先确认 `CHROMA_PERSIST_DIR` 指向的是开发环境，
别把生产向量库清掉。

---

## 权限分级（agent / admin）

- chunk metadata 有 `access_level`（`agent` / `admin`），来源 `config.RAG_DOC_ACCESS`；缺省 `agent`
- 向量路走 Chroma metadata filter，BM25 路按角色预建索引——两条路都过滤，不能只过滤最终结果
- `rag_node` 从 `obs.current_user()` 取角色；评测用例用可选 `user_role`
- 当前 admin 文档只有 `dispute_resolution_policy.md`；改分级或文档内容后必须重建 collection
  （现用 `data/chroma_v2` + `aftersale_policy_v2`，旧库保留可回滚）

## 常见任务

### 新增一篇知识库文档

1. 在 `rag/documents/` 下加 `xxx_policy.md`，标题层级清晰（切分依赖标题）
2. 在 `agent/` 目录、用项目 `.venv` 跑入库入口
   - `python -m cross_ecommerce_agent.rag.ingest`
   - 若参数不对，直接看 `rag/ingest.py` 的 `__main__` 块，那里是**唯一准绳**，不要凭记忆敲
3. **完成标准**：用 `agent/scripts/compare_retrieval.py` 或直接问一句该文档覆盖的问题，能召回对应段落且引用到正确文档名。

### 重建索引

1. 确认当前指向的不是生产库
2. 清掉 `data/chroma` 下对应集合（或让 ingest 走重建分支）
3. 重跑入库
4. **完成标准**：`python eval/run_eval.py --category 政策问答` 不出现新的失败用例

### 检索效果变差，先定位是哪一层

**不要一上来就改 prompt。** 按这个顺序二分：

```
1. 用 compare_retrieval.py 对比「纯向量」vs「混合」
   ├─ 两者都召不回 → 切分或 embedding 的问题（往下看 2）
   └─ 纯向量召得回、混合召不回 → BM25 或 RRF 融合的问题
2. 两者都召不回 → 先看切分粒度：
   段落被切碎了（关键词丢上下文）还是过长（一个 chunk 里混了多个主题）
3. 切分没问题 → 看 query_rewrite 是不是把问题改坏了
   `python -m cross_ecommerce_agent.rag.query_rewrite` 可单独验证改写结果
```

**有一条经验：政策类问答答错，八成是检索召回了错误条款，不是模型理解错。**
先查召回，再动 prompt。

---

## 验证

RAG 没有单元测试，验证靠三层：

| 层 | 命令 | 看什么 |
|---|---|---|
| 单点检索 | `agent/scripts/compare_retrieval.py` | 纯向量 vs 混合的召回差异 |
| 改写质量 | `agent/scripts/compare_rewrite.py` | 改写前后 query 的差异 |
| 端到端 | `python eval/run_eval.py --category 政策问答` | 37 条用例里的政策类子集 |

**改 prompt 或检索后，先跑单类（`--category 政策问答`），不要一上来跑全量。**

改完 RAG 后如果动了图拓扑或节点行为，还要按 `verify-change` skill 走完整阶梯。

---

## 已知坑

| 现象 | 根因 | 处理 |
|---|---|---|
| 入库 400 `batch size is invalid` | embedding 单批超 10 | 检查 `BATCH_SIZE` 与循环 |
| 入库 400 且文本看起来正常 | 用了 `langchain_openai` 默认 embeddings | 换回 `OpenAICompatibleEmbeddings` |
| 新环境第一次检索就崩 | 空 Chroma → BM25 除零 | 保留空库保护分支 |
| 改了文档但答案还是老的 | 增量入库残留旧 chunk | 重建集合 |
| 召回总是同一篇文档 | 集合里重复入库了多次 | 重建，不要反复增量灌同一批文件 |
| 中文检索几乎失效 | jieba 分词没生效或语料为空 | 检查分词器初始化与语料条数 |

---

## 边界

- **不要**在 `rag/` 里加业务逻辑（查订单、改库存），那属于 `tools/` 和 `business-system/`。
- **不要**把 RAG 改成直连数据库取数——违反 CLAUDE.md 的第一条不变量（Agent 只经 REST API 取数）。
- 新增依赖必须写进 `agent/requirements.txt`，`.venv` 里装过不算。
