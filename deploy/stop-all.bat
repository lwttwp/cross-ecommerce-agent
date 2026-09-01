@echo off
chcp 65001 >nul
rem ============================================
rem  cross-ecommerce-agent 一键停止
rem  用法: deploy\stop-all.bat
rem ============================================
cd /d %~dp0..

echo 停止容器(保留数据卷)...
docker compose down
echo.
echo 如需连数据一起清空(重新造数): docker compose down -v
