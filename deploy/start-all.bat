@echo off
chcp 65001 >nul
rem ============================================
rem  cross-ecommerce-agent 一键启动
rem  用法: deploy\start-all.bat
rem ============================================
cd /d %~dp0..

echo [1/3] 构建并启动容器...
docker compose up -d --build
if errorlevel 1 (
    echo [失败] 容器启动失败,请检查 docker 是否运行
    exit /b 1
)

echo [2/3] 等待服务就绪(约 30s,PG 冷启动较慢)...
timeout /t 30 /nobreak >nul

echo [3/3] 健康检查:
curl -s http://127.0.0.1:8000/api/v1/health
echo.

echo.
echo ============================================
echo  业务系统后台:  http://127.0.0.1:8000
echo  健康检查:      http://127.0.0.1:8000/api/v1/health
echo  RabbitMQ 管理: http://127.0.0.1:15673  (guest/guest)
echo  若健康检查非 {"code":0} 或 nginx unhealthy:
echo    docker exec ce-php-web nginx -s reload
echo ============================================
