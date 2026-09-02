# AI 聊天服务镜像: LangGraph 图 + FastAPI WebSocket
# 构建: docker compose build chat
FROM python:3.11-slim

# chromadb(onnxruntime) 需要 libgomp;curl 用于健康检查
RUN apt-get update && apt-get install -y --no-install-recommends \
    libgomp1 curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# 依赖层(单独 COPY 利用 Docker 层缓存)
COPY requirements.txt .
# 国内网络用清华源加速;不需要可删掉 -i 参数
RUN pip install --no-cache-dir -r requirements.txt -i https://pypi.tuna.tsinghua.edu.cn/simple

# 代码层(运行时会被 compose 的 ./agent:/app bind mount 覆盖,仅保证镜像可独立运行)
COPY . .

ENV PYTHONUNBUFFERED=1
EXPOSE 8082
CMD ["python", "chat_server.py"]
