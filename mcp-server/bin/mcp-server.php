#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * MCP Server 入口(stdio 传输)。
 *
 * 用法:
 *   php bin/mcp-server.php
 *
 * stdio 传输: 每行一个 JSON-RPC 消息(请求/响应),无 Content-Length 头。
 * 环境配置: 优先真实环境变量,其次读取 mcp-server/.env。
 */

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/BusinessApiClient.php';
require __DIR__ . '/../src/ToolRegistry.php';
require __DIR__ . '/../src/McpServer.php';
require __DIR__ . '/../src/Tools/BusinessTools.php';

use Mcp\BusinessApiClient;
use Mcp\Env;
use Mcp\McpServer;
use Mcp\ToolRegistry;
use Mcp\Tools\BusinessTools;

// ---------- 配置 ----------
$env = Env::merge(Env::load(dirname(__DIR__) . '/.env'));
$baseUrl = $env['BIZ_API_BASE'] ?? 'http://127.0.0.1:8000/api/v1';
$token = $env['BIZ_API_TOKEN_AGENT'] ?? '';
if ($token === '') {
    fwrite(STDERR, "[MCP] 错误: 缺少 BIZ_API_TOKEN_AGENT(agent 角色业务 API Token)\n");
    exit(1);
}

// ---------- 组装 ----------
$api = new BusinessApiClient($baseUrl, $token);
$registry = new ToolRegistry();
foreach (BusinessTools::build($api) as $tool) {
    $registry->register($tool['name'], $tool['description'], $tool['inputSchema'], $tool['handler']);
}
$server = new McpServer($registry);
fwrite(STDERR, sprintf("[MCP] %s v%s 就绪, 工具 %d 个, BIZ_API_BASE=%s\n",
    $server->serverName ?? 'cross-ecommerce-mcp',
    '1.0.0',
    count($registry->all()),
    $baseUrl,
));

// ---------- stdio 主循环 ----------
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    $response = $server->handle(is_array($msg) ? $msg : null);
    if ($response !== null) {
        fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
        fflush(STDOUT);
    }
}
