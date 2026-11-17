# 后台 RAG 管理页面设计

| 项目 | cross-ecommerce-agent |
|---|---|
| 日期 | 2026-11-17 |
| 状态 | 已实现（2026-11-17） |
| 范围 | Laravel 后台 `/admin/rag` + chat 内部 `/api/rag/*` |

## 1. 目标

在管理后台提供 RAG 运维页面：

- 上传新知识库文档，并选择权限（`agent` / `admin`）
- 重建索引（全量重新入库 + 重建 BM25）
- 清空向量库（只删向量，不删源文档；可重建恢复）
- 查看当前 collection、文档列表、分级 chunk 数

## 2. 非目标

- 不做文档在线编辑；上传后不提供删除文档的 UI（本次只做上传/重建/清空）
- 不做多租户/按员工分级；沿用 `agent` / `admin` 两级
- 不改变内置文档的提交方式；内置文档仍在 `rag/documents/`

## 3. 架构

```
浏览器 /admin/rag
   │ session cookie
   ▼
Laravel Web\RagController  role:admin
   │ 转发 Cookie 到 http://chat:8082/api/rag/*
   ▼
chat FastAPI  require_user + require_admin
   ▼
RagService（单 Chroma client）
   ├── 上传：data/rag_documents/ + data/rag_access.json
   ├── 重建：清 chunk → 重嵌入内置+上传文档 → 新 HybridRetriever 热切换
   ├── 清空：删 chunk → 空 retriever
   └── 状态：文档/分级 chunk/最后操作
```

- 不新增服务、不共享卷、不新增管理员 token；复用现有 Laravel session SSO
- 文档与向量只在 chat 容器内操作；Laravel 只做页面、代理与审计
- 操作成功后在 Laravel 侧 `AuditLogger::log`，审计仍由业务系统落库

## 4. 存储

| 内容 | 位置 | 是否入库 |
|---|---|---|
| 内置文档 | `agent/src/cross_ecommerce_agent/rag/documents/` | 是 |
| 运行时上传 | `agent/data/rag_documents/` | 否（gitignore） |
| 上传文档权限 | `agent/data/rag_access.json` | 否（gitignore） |
| 向量库 | `agent/data/chroma_v2` / `aftersale_policy_v2` | 否 |

- 内置文档权限：`config.RAG_DOC_ACCESS`（当前 `dispute_resolution_policy.md=admin`）
- 上传文档权限：`data/rag_access.json`（`{filename: level}`）
- 读取时合并：运行时映射优先，其次 `RAG_DOC_ACCESS`，最后 `RAG_ACCESS_DEFAULT=agent`

## 5. chat 内部 API

全部要求管理员（`require_admin`，底层仍是 Laravel session 验票）。

### `GET /api/rag/status`

```json
{
  "ok": true,
  "collection": "aftersale_policy_v2",
  "persist_dir": "/app/data/chroma_v2",
  "chunks": {"agent": 94, "admin": 6, "total": 100},
  "documents": [
    {"name": "return_policy.md", "origin": "builtin", "access_level": "agent", "size": 1234},
    {"name": "vip_policy.md", "origin": "upload", "access_level": "admin", "size": 800}
  ],
  "busy": false,
  "last_operation": {"action": "rebuild", "at": "2026-11-17T14:00:00", "detail": "100 chunks"}
}
```

### `POST /api/rag/upload`

JSON（Laravel 读上传文件后转发，避免 multipart 依赖）：

```json
{"filename": "vip_policy.md", "content": "# ...", "access_level": "admin"}
```

- 校验：`basename`、`.md`、文件名白名单、长度 ≤ 100、内容 ≤ 2MB、level ∈ {agent,admin}
- 重名：内置或上传目录已存在则拒绝
- 保存到 `data/rag_documents/`，更新 `data/rag_access.json`
- **立即增量入库**该文档并刷新 BM25，返回 `chunks_added`

### `POST /api/rag/rebuild`

- 获取写锁；删除当前 collection 全部 chunk；清空 md5 去重文件
- 重新入库内置目录 + 上传目录全部文档；重建 BM25；构建新 `HybridRetriever` 并热切换
- 返回 `chunks`、`duration_ms`；期间检索仍可用旧 retriever

### `POST /api/rag/clear`

```json
{"confirm": "CLEAR"}
```

- `confirm` 不是 `CLEAR` 直接 400
- 获取写锁；删除全部 chunk；切换为空 retriever；返回 `deleted`
- 源文档与权限映射保留，重建可恢复

## 6. RagService 设计

- 单例，持有唯一的 `Chroma` store；`KnowledgeBaseService(chroma=store)` 与 `HybridRetriever(store=store)` 共享，避免多客户端写锁
- 写操作（上传/重建/清空）用 `threading.Lock` 串行
- 检索：`invoke(query, role)` 读取当前 retriever 引用后调用；重建/清空在完成后原子替换引用，旧请求不受影响
- `nodes.py` 的 `_hybrid` 全局改为 `rag_service.invoke(...)`
- 上传/重建/清空在 FastAPI 中通过 `asyncio.to_thread` 执行，避免阻塞事件循环

## 7. Laravel 页面

- 路由（`role:admin`）：
  - `GET /admin/rag` 状态 + 上传表单 + 文档表 + 重建/清空按钮
  - `POST /admin/rag/upload`
  - `POST /admin/rag/rebuild`
  - `POST /admin/rag/clear`
- `Web\RagController` 使用 Laravel HTTP client 调 `config('services.chat.base_url')`，转发 `Cookie` 头
- 超时：status 10s；upload 60s；rebuild 180s；clear 30s
- 校验：`document` 为 `.md` 且 ≤ 2MB；`access_level` in agent/admin；clear 的 `confirm === 'CLEAR'`
- 审计：
  - `rag.upload`：filename、access_level、chunks_added
  - `rag.rebuild`：chunks、duration_ms
  - `rag.clear`：deleted
  - 不记录文档正文
- 视图 `views/rag/index.blade.php`：状态卡片、上传表单、文档表（名称/来源/权限/大小）、重建按钮、清空表单（输入 CLEAR）、flash
- 侧边栏加“📚 RAG 管理”，仅管理员可见

## 8. 配置

```python
RAG_UPLOAD_DIR = BASE_DIR / os.getenv('RAG_UPLOAD_DIR', 'data/rag_documents')
RAG_ACCESS_POLICY_PATH = BASE_DIR / os.getenv('RAG_ACCESS_POLICY_PATH', 'data/rag_access.json')
RAG_MAX_DOC_BYTES = 2 * 1024 * 1024
```

Laravel `.env.example` 增加 `CHAT_INTERNAL_BASE=http://chat:8082`。

## 9. 安全与风险

- 两层管理员校验：Laravel `role:admin` + chat `require_admin`
- 文件名去路径、白名单、重名拒绝；内容只按文本入库，不在页面渲染正文
- 清空要求输入 `CLEAR`，并写审计；源文档保留，重建可恢复
- 重建期间 BM25/向量短暂重建，检索继续用旧 retriever
- 镜像层缺 `langchain-text-splitters`，上线前必须 `docker compose up -d --build chat`
- 上传目录/权限文件在 `data/` 下，不进 Git；测试文档用后需手动清理并重建

> 实现备注：`docker compose up -d --build chat` 因 Docker Hub 元数据提交失败未完成；
> 运行中的 chat 容器已临时 `pip install langchain-text-splitters` 并重启完成验证。
> 正式部署需在网络恢复后重建镜像（`requirements.txt` 已包含该依赖）。

## 10. 验收标准

1. 非管理员访问 `/admin/rag` 403；chat `/api/rag/*` 非管理员 403
2. 上传 agent 文档 → agent/admin 都能检索；上传 admin 文档 → 仅 admin 能检索
3. 重名、非 `.md`、>2MB、路径穿越文件名都被拒绝
4. 重建后 status 的 chunk 数与实际一致；审计有 `rag.rebuild`
5. 清空后检索为空；重建后恢复；审计有 `rag.clear`
6. 上传/重建/清空期间其他聊天检索不崩溃
7. 现有 37 条 eval 不回归（内置文档与权限不变）
