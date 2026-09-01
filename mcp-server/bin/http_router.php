<?php

declare(strict_types=1);

/**
 * MCP Server HTTP 传输路由(streamable HTTP, MCP 2025-03-26)。
 *
 * 用法:
 *   php -S 127.0.0.1:8081 bin/http_router.php
 *
 * 端点:
 *   POST /message  JSON-RPC 请求 → JSON 响应(无流模式)
 *   GET  /sse      服务端→客户端通知流(无流模式下客户端不依赖)
 *
 * 说明: 无状态模式(stateless),每个请求独立处理,不强制 Mcp-Session-Id,
 * 客户端(mcp SDK streamablehttp_client)在服务端返回 application/json 时进入无流模式。
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

function make_server(): McpServer
{
    static $server = null;
    if ($server !== null) {
        return $server;
    }
    $env = Env::merge(Env::load(dirname(__DIR__) . '/.env'));
    $baseUrl = $env['BIZ_API_BASE'] ?? 'http://127.0.0.1:8000/api/v1';
    $token = $env['BIZ_API_TOKEN_AGENT'] ?? '';
    if ($token === '') {
        throw new RuntimeException('缺少 BIZ_API_TOKEN_AGENT');
    }
    $api = new BusinessApiClient($baseUrl, $token);
    $registry = new ToolRegistry();
    foreach (BusinessTools::build($api) as $tool) {
        $registry->register($tool['name'], $tool['description'], $tool['inputSchema'], $tool['handler']);
    }
    $server = new McpServer($registry);

    return $server;
}

// ---------- CORS ----------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method === 'POST' && ($path === '/message' || $path === '/')) {
    $raw = file_get_contents('php://input');
    $msg = json_decode($raw === false ? '' : $raw, true);

    try {
        $server = make_server();
        $resp = $server->handle(is_array($msg) ? $msg : null);
    } catch (Throwable $e) {
        $resp = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => $e->getMessage()]];
    }

    header('Content-Type: application/json');
    // 通知(无 id)无响应 → 空 JSON 体,HTTP 200
    echo json_encode($resp ?? new stdClass(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && $path === '/sse') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    // 无流模式客户端不连此端点;提供心跳流保持规范兼容
    echo "event: endpoint\ndata: /message\n\n";
    if (function_exists('ob_end_flush')) {
        ob_end_flush();
    }
    while (true) {
        echo "event: heartbeat\ndata: " . date('c') . "\n\n";
        flush();
        sleep(15);
    }
}

if ($method === 'GET' && ($path === '/health' || $path === '/')) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'server' => 'cross-ecommerce-mcp', 'transport' => 'streamable-http']);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found', 'path' => $path]);
