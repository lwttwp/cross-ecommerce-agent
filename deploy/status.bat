@echo off
chcp 65001 >nul
rem ============================================
rem  cross-ecommerce-agent 状态检查
rem  用法: deploy\status.bat
rem ============================================
cd /d %~dp0..

echo [容器状态]
docker compose ps

echo.
echo [健康检查]
curl -s http://127.0.0.1:8000/api/v1/health
echo.

echo.
echo [提示] 若 nginx 显示 unhealthy(php-fpm 就绪后仍 502):
echo   docker exec ce-php-web nginx -s reload
