# 服务器部署手册（腾讯云 / 任意 4G+ Ubuntu 24.04）

> 2026-09-02 实战验证：腾讯云 4G / Ubuntu 24.04.4 / Docker 29.7 + Compose v5.5。
> 目标：一台干净服务器 → 全栈跑通（业务 API + 后台 + 聊天界面 + RabbitMQ 审批通知）。

## 0. 服务器要求

| 项 | 最低 | 说明 |
|---|---|---|
| 内存 | **4G** | 全栈峰值 ~1.8G（PG 300M + RabbitMQ 400M + PHP 150M + chat 500M + worker）；**2G 必 OOM** |
| 磁盘 | 40G | 代码 + PG 数据 + 镜像 ~10G；100w 订单导出 CSV 可达 1G+ |
| 系统 | Ubuntu 22.04/24.04 LTS x86_64 | 部署流程按此验证 |
| 带宽 | 3-5M | 够演示 |

安全组/防火墙只放行：SSH 端口 + 8000（Web）。**数据库/队列端口一律不对外**。

## 1. 安装 Docker（国内源，官方脚本被墙）

```bash
# get.docker.com 在国内大概率被 reset,改用阿里云 docker-ce 源
sudo apt-get update
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://mirrors.aliyun.com/docker-ce/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://mirrors.aliyun.com/docker-ce/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo systemctl enable --now docker
docker --version && docker compose version
```

## 2. Docker Hub 镜像加速（公共加速器大多已死,候选源逐个试）

```bash
sudo tee /etc/docker/daemon.json <<'EOF'
{
  "registry-mirrors": [
    "https://mirror.ccs.tencentyun.com",
    "https://docker.1ms.run",
    "https://docker.nju.edu.cn"
  ]
}
EOF
sudo systemctl daemon-reload && sudo systemctl restart docker
docker pull nginx:alpine   # 验证哪个源通
```

> 已失效勿用：docker.mirror.daocloud.io（DNS no such host）、docker.mirrors.ustc.edu.cn。
> 都挂了就用云厂商容器镜像服务的专属加速地址。

## 3. 拉代码（GitHub 国内不稳,备镜像）

```bash
cd ~ && git clone https://github.com/lwttwp/cross-ecommerce-agent.git
# GitHub 断流(TLS reset)时:
cd cross-ecommerce-agent
git remote set-url origin https://gh-proxy.com/https://github.com/lwttwp/cross-ecommerce-agent.git
git pull origin main
git remote set-url origin https://github.com/lwttwp/cross-ecommerce-agent.git
```

## 4. 配置 .env（密钥经 Xftp 上传,不经聊天/命令行）

本地文件：`business-system/.env`、`agent/.env` → 服务器对应目录（bind mount 自动生效）。

```bash
# Xftp 上传后处理 CRLF
sed -i 's/\r$//' business-system/.env agent/.env
# 聊天下载链接对外入口(必配,否则链接指向容器内网 php-web)
grep -q PUBLIC_API_BASE agent/.env || echo "PUBLIC_API_BASE=http://<公网IP>:8000/api/v1" >> agent/.env
```

注意：
- 服务器上传的 .env 若用 root/Xftp 写入,owner 是 root——755 即可读,容器没问题
- compose environment 覆盖 .env 的 DB 地址/RabbitMQ/BIZ_API_BASE（容器内网地址）,无需改
- **密钥若曾在聊天/日志出现,部署后轮换**（DeepSeek/百炼 key 控制台重生成,业务 token 改 .env 两端同步）

## 5. 首次启动（构建 10-20 分钟）

```bash
nohup docker compose up -d --build > /tmp/deploy.log 2>&1 &
tail -f /tmp/deploy.log
# 验证
curl -s http://127.0.0.1:8000/api/v1/health   # {"code":0}
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/chat/   # 200
```

入口：业务后台 `http://IP:8000/`（admin@example.com / admin123）、聊天 `http://IP:8000/chat/`。

## 6. RAG 入库（全新环境向量库为空,chat 会崩）

```bash
# chat 崩 ZeroDivisionError = Chroma 空库,先入库(补 PYTHONPATH;缺包先临时装)
docker compose run --rm --no-deps chat sh -c \
  "pip install langchain-text-splitters -q -i https://pypi.tuna.tsinghua.edu.cn/simple && PYTHONPATH=/app/src python src/cross_ecommerce_agent/rag/ingest.py"
docker compose up -d chat
```

## 7. 百万级闭环数据

```bash
nohup docker compose exec php-api php artisan data:mega --orders=1000000 > /tmp/mega.log 2>&1 &
tail -f /tmp/mega.log   # 10-20 分钟,清空业务表重建(products/exchange_rates 保留)
```

## 8. 性能索引（100w 数据必做）

```bash
docker compose exec php-api php artisan migrate --force   # trgm + text_pattern_ops 索引
```

后台搜索/API 列表查询自动受益（详见 docs/CHANGELOG.md v1.1.0）。

## 9. 已知坑速查

| 症状 | 原因 | 处理 |
|---|---|---|
| chat 容器 502/ZeroDivisionError | Chroma 空库(全新环境) | 跑 ingest 入库(见 §6) |
| ModuleNotFoundError langchain_text_splitters / checkpoint.postgres | requirements 漏依赖 | 临时 pip install 或 git pull 后重建镜像 |
| Laravel storage Permission denied | bind mount 目录属主 root,FPM(www-data) 不可写 | `chmod -R 777 business-system/storage business-system/bootstrap/cache` |
| 报表任务卡 running / worker exit 255 | 100w 导出 get() 全量内存爆(已修复 chunk) | git pull + `docker compose up -d php-worker` |
| 下载链接打不开 | PUBLIC_API_BASE 未配(链接指向 php-web 内网) | §4 配置公网地址 |
| PHP 代码改动不生效 | opcache | `docker compose restart php-api` |
