# -*- coding: utf-8 -*-
"""RAG 管理服务：后台上传文档、重建索引、清空向量库。

设计要点：
- 单例 + 单 Chroma client：KnowledgeBaseService 与 HybridRetriever 共享同一个 store，
  避免多客户端写锁（Windows/新版 Chroma 容易撞）。
- 写操作用 threading.Lock 串行；检索只读当前 retriever 引用，重建完成后热切换。
- 上传文档写 data/rag_documents/，权限写 data/rag_access.json；内置文档不动。
- 清空只删向量，保留源文档与权限映射，重建可恢复。
"""
from __future__ import annotations

import os
import re
import threading
import time
from datetime import datetime

import cross_ecommerce_agent.config as config
from cross_ecommerce_agent.rag.access import access_level_for, save_runtime_access
from cross_ecommerce_agent.rag.ingest import KnowledgeBaseService
from cross_ecommerce_agent.rag.retriever import (
    HybridRetriever,
    VectorStoreService,
    _doc_access_level,
)

_FILENAME_RE = re.compile(r'^[A-Za-z0-9_\-. 一-龥]{1,96}\.md$')


def _now() -> str:
    return datetime.now().isoformat(timespec='seconds')


class RagService:
    def __init__(self):
        self._lock = threading.Lock()
        self._store = VectorStoreService().vector_store
        self._retriever = HybridRetriever(store=self._store)
        self._busy = False
        self._last_operation: dict | None = None

    # ---------------- 检索 ----------------
    def invoke(self, query: str, role: str | None = None):
        """读取当前 retriever 引用后调用；重建/清空完成时原子替换引用。"""
        retriever = self._retriever
        return retriever.invoke(query, role=role)

    # ---------------- 状态 ----------------
    def status(self) -> dict:
        chunks = {'agent': 0, 'admin': 0, 'total': 0}
        for doc in self._retriever._corpus:
            level = _doc_access_level(doc)
            chunks[level] = chunks.get(level, 0) + 1
            chunks['total'] += 1

        return {
            'collection': config.CHROMA_COLLECTION,
            'persist_dir': str(config.CHROMA_PERSIST_DIR),
            'chunks': chunks,
            'documents': self._documents(),
            'busy': self._busy,
            'last_operation': self._last_operation,
        }

    def _documents(self) -> list[dict]:
        documents = []
        for origin, docs_dir in (('builtin', config.RAG_DOCS_DIR), ('upload', config.RAG_UPLOAD_DIR)):
            if not docs_dir.exists():
                continue
            for name in sorted(os.listdir(docs_dir)):
                if not name.endswith('.md'):
                    continue
                path = docs_dir / name
                try:
                    size = path.stat().st_size
                except OSError:
                    size = 0
                documents.append({
                    'name': name,
                    'origin': origin,
                    'access_level': access_level_for(name),
                    'size': size,
                })
        return documents

    # ---------------- 上传 ----------------
    def upload(self, filename: str, content: str, access_level: str) -> dict:
        name = self._validate_filename(filename)
        if access_level not in ('agent', 'admin'):
            raise ValueError('权限级别只支持 agent/admin')
        if not content.strip():
            raise ValueError('文档内容不能为空')
        if len(content.encode('utf-8')) > config.RAG_MAX_DOC_BYTES:
            raise ValueError('文档超过 2MB 限制')
        if (config.RAG_DOCS_DIR / name).exists() or (config.RAG_UPLOAD_DIR / name).exists():
            raise ValueError('同名文档已存在，请改名后再上传')

        with self._lock:
            # 先写权限映射，再落文件：万一文件写失败，只会留下一条指向不存在文件的映射，
            # 不会出现 admin 文档因映射缺失被当成 agent 的可越权窗口。
            save_runtime_access(name, access_level)
            config.RAG_UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
            path = config.RAG_UPLOAD_DIR / name
            tmp = path.with_suffix(path.suffix + '.tmp')
            tmp.write_text(content, encoding='utf-8')
            os.replace(tmp, path)

            # 增量入库 + 刷新 BM25
            kb = KnowledgeBaseService(chroma=self._store)
            added, total = kb.upload_by_str(content, name)
            self._retriever.rebuild_index()
            self._last_operation = {
                'action': 'upload',
                'at': _now(),
                'detail': f'{name} ({access_level})',
            }
            return {
                'filename': name,
                'access_level': access_level,
                'chunks_added': added,
                'chunks_total': total,
            }

    # ---------------- 重建 ----------------
    def rebuild(self) -> dict:
        with self._lock:
            self._busy = True
            started = time.perf_counter()
            try:
                self._delete_all_chunks()
                config.md5_path.parent.mkdir(parents=True, exist_ok=True)
                config.md5_path.write_text('', encoding='utf-8')

                kb = KnowledgeBaseService(chroma=self._store)
                added = total = 0
                for docs_dir in (config.RAG_DOCS_DIR, config.RAG_UPLOAD_DIR):
                    if not docs_dir.exists():
                        continue
                    for name in sorted(os.listdir(docs_dir)):
                        if not name.endswith('.md'):
                            continue
                        content = (docs_dir / name).read_text(encoding='utf-8')
                        a, t = kb.upload_by_str(content, name)
                        added += a
                        total += t

                new_retriever = HybridRetriever(store=self._store)
                self._retriever = new_retriever
                duration_ms = int((time.perf_counter() - started) * 1000)
                chunks = len(new_retriever._corpus)
                self._last_operation = {
                    'action': 'rebuild',
                    'at': _now(),
                    'detail': f'{chunks} chunks',
                }
                return {'chunks': chunks, 'added': added, 'duration_ms': duration_ms}
            finally:
                self._busy = False

    # ---------------- 清空 ----------------
    def clear(self) -> dict:
        with self._lock:
            deleted = self._delete_all_chunks()
            config.md5_path.parent.mkdir(parents=True, exist_ok=True)
            config.md5_path.write_text('', encoding='utf-8')
            self._retriever.rebuild_index()
            self._last_operation = {
                'action': 'clear',
                'at': _now(),
                'detail': f'deleted {deleted}',
            }
            return {'deleted': deleted}

    def _delete_all_chunks(self) -> int:
        data = self._store._collection.get(include=[])
        ids = data.get('ids') or []
        if ids:
            self._store._collection.delete(ids=ids)
        return len(ids)

    @staticmethod
    def _validate_filename(filename: str) -> str:
        name = (filename or '').strip()
        if not name or os.path.basename(name) != name or '/' in name or '\\' in name:
            raise ValueError('文件名不合法')
        if not _FILENAME_RE.fullmatch(name):
            raise ValueError('只支持 .md 文件，文件名仅允许中英文/数字/下划线/点/短横线/空格，且不超过 100 字符')
        return name


rag_service = RagService()
