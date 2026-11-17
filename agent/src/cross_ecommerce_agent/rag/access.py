# -*- coding: utf-8 -*-
"""知识库权限映射：内置文档走 config.RAG_DOC_ACCESS，后台上传文档走运行时 JSON。"""
from __future__ import annotations

import json
import os

import cross_ecommerce_agent.config as config

VALID_LEVELS = ('agent', 'admin')


def _read_runtime() -> dict[str, str]:
    path = config.RAG_ACCESS_POLICY_PATH
    try:
        data = json.loads(path.read_text(encoding='utf-8'))
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        return {}
    documents = data.get('documents') if isinstance(data, dict) else None
    if not isinstance(documents, dict):
        return {}
    return {str(k): str(v) for k, v in documents.items() if v in VALID_LEVELS}


def access_level_for(filename: str) -> str:
    """运行时映射优先，其次内置映射，最后默认 agent；非法值 fail closed 到 agent。"""
    level = (
        _read_runtime().get(filename)
        or config.RAG_DOC_ACCESS.get(filename)
        or config.RAG_ACCESS_DEFAULT
    )
    return level if level in VALID_LEVELS else config.RAG_ACCESS_DEFAULT


def save_runtime_access(filename: str, level: str) -> None:
    """写上传文档的权限映射（原子替换）。"""
    if level not in VALID_LEVELS:
        raise ValueError('权限级别只支持 agent/admin')

    path = config.RAG_ACCESS_POLICY_PATH
    path.parent.mkdir(parents=True, exist_ok=True)
    documents = _read_runtime()
    documents[filename] = level

    tmp = path.with_suffix(path.suffix + '.tmp')
    tmp.write_text(
        json.dumps({'version': 1, 'documents': documents}, ensure_ascii=False, indent=2),
        encoding='utf-8',
    )
    os.replace(tmp, path)
