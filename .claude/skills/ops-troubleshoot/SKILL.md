---
name: ops-troubleshoot
description: 容器起不来、接口 502、健康检查不过、审批通知收不到、报表导出读不到文件、RAG 报 BM25 除零、容器缺 Python 包时用这个 skill。它把这套六服务 Docker Compose 架构下踩过的坑，按「症状 → 根因 → 命令」组织成排查表，比照着源码顺藤摸瓜快得多。用户说容器、docker、502、unhealthy、nginx、worker、日志、起不来、重建、部署时用这个 skill。
---

# 环境排障

六个服务：`postgres / rabbitmq / php-api / php-web / chat / php-worker`，容器名统一 `ce-*`。
端口刻意错开本机原生服务：nginx `8000`、postgres `5433`、rabbitmq `5673` / `15673`。

## 先看全局

```bash
deploy\status.bat
docker compose ps
curl http://127.0.0.1:8000/api/v1/health    # {"code":0} 即就绪
```

启停：`deploy\start-all.bat` / `deploy\stop-all.bat`
（**加 `-v` 会清库**，停之前想清楚）。

单个服务重建：`docker compose up -d --build chat`。

## 症状 → 根因 → 处置

| 症状 | 根因 | 处置 |
|---|---|---|
| nginx unhealthy 但 php-fpm 正常 / 接口 502 | 依赖服务晚启动，nginx 把旧 IP 缓存进 DNS | `docker exec ce-php-web nginx -s reload` |
| 健康检查一直不过，刚 `start-all` 就连不上库 | PG 首次建库可能 > 60s | 等。**等库就绪要用 `PDO SELECT 1` 循环，不能只探端口** —— 端口通了库还没好 |
| `/bin/sh: exec format error` | `.sh` 存成了 CRLF 或带 BOM | Alpine 的 `.sh` 必须 LF 无 BOM |
| 导出文件下载 404 / 读不到 | worker 以 root 写，FPM(www-data) 读不到 | 导出后 `chmod`；bind mount 的 `storage` / `bootstrap/cache` 同理（`a+rwX`） |
| 导出任务卡 running，worker exit 255 | 全量 `get()` 拉爆 PHP 内存 | 已修（`chunkById(2000)` + 流式写 CSV）。改报表逻辑别退回全量 |
| RAG 报 `BM25Okapi` 除零 | 全新环境 Chroma 空库 | `HybridRetriever` 已有空库保护跳过 BM25 路，别删 |
| 容器缺 Python 包，本地却正常 | `agent/requirements.txt` 漏了 | 补进去再 `--build`。本地 `.venv` 有 ≠ 容器有 |
| embedding 端点 400 | 百炼单次最多 10 条文本；兼容端点只认字符串输入 | `BATCH_SIZE=10`；不要走默认 tiktoken 转换（`rag/embeddings.py` 已绕开） |
| composer 卡死 / 目录删不掉 | 中断的 composer 留孤儿 git 进程卡在 `git clone --mirror` | kill 掉那些 git 进程再删 |
| 改了代码容器没生效 | — | `docker compose up -d --build <service>` |

## 审批通知收不到

事件链有三段，逐段定位，**别从 LangGraph 开始猜**（审批本来是异步的，不挂起图）：

```bash
docker compose logs -f php-worker    # ① 审批有没有走到 RefundEventPublisher::publish
docker compose logs -f chat          # ② ChatEventBridge 有没有消费到 + WS 推送
```

- ① 发布失败**只记 log、不阻断审批主流程**，所以日志里可能只有一行 warning，容易漏
- ③ 查 `refund_mapper` 里有没有 `refund_no → thread_id` 映射 —— 没映射就找不到会话，通知无处可投
- 还要检查聊天服务的 `RABBITMQ_PASSWORD`：以 compose 的 `environment` 为准，它会**覆盖** `env_file`

细节见 `refund-flow` skill。

## 日志与运维命令

```bash
docker compose logs -f chat            # Agent 服务
docker compose logs -f php-worker      # 异步任务 / 审批事件
docker compose logs -f php-api
docker compose exec php-api php artisan migrate
```

## 生产部署

全套坑（国内 Docker 源、镜像加速、密钥上传、RAG 入库等）已经写在
`deploy/server-setup.md` —— **先读它，不要重新踩一遍**。生产是腾讯云 4G / Ubuntu 24.04。
