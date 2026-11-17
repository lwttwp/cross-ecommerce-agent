# RAG 知识库权限分级设计

| 项目 | cross-ecommerce-agent |
|---|---|
| 日期 | 2026-11-17 |
| 状态 | 已实现（2026-11-17） |
| 范围 | Agent 侧 RAG（`agent/src/cross_ecommerce_agent/rag/` + `graph/nodes.py` + `eval/`） |

## 1. 目标

让知识库检索按当前登录员工的角色分级：

- `agent`（客服/运营）只能召回客服级文档；
- `admin`（管理员）可以额外召回管理员级文档；
- 过滤发生在**检索层**，不能依赖 prompt 让模型“自觉不看”；
- 不改变现有 34 条评测的默认行为（默认角色 `agent`）。

## 2. 非目标

- 不做未登录/访客级（`public`）；聊天页本身要求登录。
- 不做按部门、按员工、按租户的细粒度 ACL。
- 不做 chunk 级混排分级；同一文档内混有管理员段落的，拆成两篇文档。
- 不把知识库文档迁到业务系统管理；documents 仍是本地 Markdown。

## 3. 权限模型

现有用户角色只有 `agent` 和 `admin`，直接复用，不新增角色表。

| 角色 | 可召回级别 | 说明 |
|---|---|---|
| `agent` | `agent` | 客服/运营 |
| `admin` | `agent` + `admin` | 管理员，额外可见内部文档 |

未知/缺失角色一律按 `agent` 处理（fail closed 到较低权限）。

级别常量放在 `config.py`：

```python
RAG_DEFAULT_ROLE = "agent"
RAG_ACCESS_DEFAULT = "agent"          # chunk 缺 metadata 时的兜底级别
RAG_ACCESS_LEVELS = {
    "agent": ("agent",),
    "admin": ("agent", "admin"),
}
RAG_DOC_ACCESS = {
    # 只列 admin；其余文档默认 agent
    "dispute_resolution_policy.md": "admin",
}
```

## 4. 文档分级

| 级别 | 文档 |
|---|---|
| `admin` | `dispute_resolution_policy.md`（争议升级、举证责任、chargeback 处理，属内部口径） |
| `agent` | 其余 14 篇，含 `customer_service_policy.md`（服务时间/联系方式/响应时效，面向客户）和 `account_policy.md`（注册/安全/注销，面向客户） |

分级粒度为**整篇文档**。新增文档默认 `agent`；要设为 admin 必须在 `RAG_DOC_ACCESS` 中显式登记。

## 5. 入库：chunk metadata

`rag/ingest.py` 的 `KnowledgeBaseService.upload_by_str(data, file_name)`：

- 根据 `config.RAG_DOC_ACCESS` 取 `access_level`，缺省 `config.RAG_ACCESS_DEFAULT`；
- 给每个 chunk 写入 `metadata["access_level"]`，与现有 `source/create_time/operator` 并列；
- 不改变切分策略（`MarkdownHeaderTextSplitter`）与 md5 去重逻辑。

**关键**：现有 chunk 没有 `access_level`，md5 去重会跳过它们，因此必须全量重建（见 §10）。

## 6. 检索过滤

`rag/retriever.py` 的 `HybridRetriever`：

1. `rebuild_index()` 按角色预建检索资源：
   - `_corpus_by_role[role]`：该角色可见的 Document 列表；
   - `_bm25_by_role[role]`：该角色语料上的 `BM25Okapi`；
   - 空语料时 `_bm25_by_role[role] = None`，保留现有空库保护（禁止除零）。
2. `invoke(query, role="agent", k=None)`：
   - `allowed = config.RAG_ACCESS_LEVELS.get(role, ("agent",))`；
   - 向量路：`similarity_search_with_score(query, k=..., filter={"access_level": {"$in": list(allowed)}})`；
   - BM25 路：只用 `_bm25_by_role[role]` 与 `_corpus_by_role[role]` 打分；
   - RRF 融合逻辑不变；
   - 缺 `access_level` 的旧 chunk 在构建 `_corpus_by_role` 时按 `RAG_ACCESS_DEFAULT` 兜底。

若安装的 Chroma 版本不支持 `$in`，降级为：
`{"$or": [{"access_level": "agent"}, {"access_level": "admin"}]}`（实现时先验证一次）。

## 7. 图节点与角色来源

`graph/nodes.py::rag_node`：

```python
role = (obs.current_user() or {}).get("role") or config.RAG_DEFAULT_ROLE
...
rag_doc = _hybrid.invoke(query, role=role)
```

- `obs` 需要新增 import；
- 角色来源是 `chat_server.run_graph_and_stream` 里已有的 `obs.bind(user=user.as_context())`；
- 评测/CLI/`compare_retrieval.py` 不绑定上下文时，默认 `agent`；
- prompt 末尾追加一句“当前角色：客服/管理员；只依据授权参考资料；查不到按第 3 条转人工”，作为纵深防御，但**不是权限边界**。

## 8. 评测

`cases.jsonl` 增加可选字段 `user_role`（默认 `agent`）：

```json
{"id":"policy-admin-01","category":"政策问答","input":"争议升级后由谁复核，多久出结果","user_role":"agent","expect":{"type":"contains_any","keywords":["无法","权限","联系管理员","暂无","转人工"]}}
{"id":"policy-admin-02","category":"政策问答","input":"争议升级后由谁复核，多久出结果","user_role":"admin","expect":{"type":"contains","keywords":["主管","5"]}}
{"id":"policy-agent-01","category":"政策问答","input":"在线客服服务时间是几点到几点","user_role":"agent","expect":{"type":"contains_any","keywords":["9:00","24:00"]}}
```

`eval/run_eval.py::run_case` 在 `_invoke` 内绑定上下文：

```python
with obs.bind(user={"id": 0, "name": "eval", "role": case.get("user_role", "agent")}):
    return graph.invoke({"user_input": case["input"]}, config=config)
```

用例总数从 34 变为 37；同步更新 `CLAUDE.md`、`.claude/skills/verify-change/SKILL.md`、`docs/CHANGELOG.md` 里的“34 条”表述。

## 9. 安全性

- 向量路与 BM25 路都过滤；只过滤最终结果会把 admin 文档带进候选并挤掉客服文档。
- 缺 metadata 兜底 `agent`，避免重建前 admin 文档意外泄露。
- 检索不到授权文档时，沿用现有 prompt 第 3 条“知识库暂未收录…转人工”，不暴露“存在但无权限”。
- `rag_docs` state 只包含授权 chunk；LLM 看不到越权内容。
- 不把 `access_level` 交给 prompt 或前端；权限只由服务端检索过滤决定。

## 10. 迁移/重建步骤

全量重建（开发/生产都要做一次）：

```bash
# 1. 停 chat，确认 CHROMA_PERSIST_DIR 指向目标环境
# 2. 清空集合（或删除 data/chroma 下对应 collection）
# 3. 清空 md5 去重文件（agent/md5.txt）
# 4. 重新入库
cd agent
python -m cross_ecommerce_agent.rag.ingest
# 5. 重启 chat（BM25 是进程内索引）
docker compose restart chat
```

重建前先备份/确认 `CHROMA_PERSIST_DIR`，避免清到生产库。

> 实现备注（2026-11-17）：本次采用**蓝绿重建**而非清库——复制旧库到 `data/chroma_v2`，
> 在新 collection `aftersale_policy_v2` 写入带 `access_level` 的 chunk；旧 `data/chroma` /
> `aftersale_policy` 保留可回滚。`CHROMA_PERSIST_DIR` / `CHROMA_COLLECTION` / `RAG_MD5_PATH`
> 已切到新值。

## 11. 验收标准

1. 以 `agent` 角色检索争议问题，返回的 `source` 不含 `dispute_resolution_policy.md`。
2. 同一问题以 `admin` 角色检索，能命中该文档。
3. 一个同时命中 admin/agent 文档的查询：`agent` 仍能拿到客服文档（不被 admin 候选挤空）。
4. `customer_service_policy.md` 对 `agent` 仍可召回。
5. 原有 34 条用例默认角色下不回归；新增 3 条边界用例通过。
6. 空 Chroma 下检索不崩溃（空库保护仍在）。
7. `obs.current_user()` 缺失时按 `agent` 处理。

## 12. 涉及文件

| 文件 | 改动 |
|---|---|
| `config.py` | 新增 `RAG_DEFAULT_ROLE`、`RAG_ACCESS_DEFAULT`、`RAG_ACCESS_LEVELS`、`RAG_DOC_ACCESS` |
| `rag/ingest.py` | chunk 写 `access_level` |
| `rag/retriever.py` | 按角色建 BM25、向量路 filter、`invoke(role=...)` |
| `graph/nodes.py` | `rag_node` 读 `obs.current_user()` 并传 role；prompt 追加角色行 |
| `eval/cases.jsonl` | 新增 `user_role` 与 3 条边界用例 |
| `eval/run_eval.py` | `run_case` 绑定 `obs` |
| `CLAUDE.md` / `verify-change` / `docs/CHANGELOG.md` | 同步用例数与行为说明 |

## 13. 风险与后续

- **重建窗口**：重建期间检索会短暂不完整；建议停 chat 后操作。
- **BM25 内存**：按角色多建一份索引；当前语料很小，可接受。若未来文档量很大，改为“过滤候选集后按需构建”。
- **文档重分级**：改 `RAG_DOC_ACCESS` 后必须重建，不能只重启。
- **P2**：如需 `public` 或按部门分级，扩展 `RAG_ACCESS_LEVELS` 即可，检索层不用改。
