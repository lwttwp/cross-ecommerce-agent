<?php

declare(strict_types=1);

/**
 * MCP Server 冒烟测试: 模拟 MCP 客户端走完整握手 + 工具调用。
 *
 * 用法:
 *   php tests/client_smoke.php
 *
 * 验证点:
 *   1. initialize 握手(协议版本协商)
 *   2. notifications/initialized
 *   3. tools/list 返回 14 个工具
 *   4. tools/call get_order 真实调用业务 API
 *   5. tools/call 不存在工具返回错误
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

$fail = 0;
$check = static function (bool $cond, string $label): void {
    global $fail;
    echo ($cond ? "  ✅ " : "  ❌ ") . $label . "\n";
    if (!$cond) {
        $fail++;
    }
};

// ---------- 1. 组装服务端(与 bin/mcp-server.php 相同) ----------
$env = Env::merge(Env::load(dirname(__DIR__) . '/.env'));
$baseUrl = $env['BIZ_API_BASE'] ?? 'http://127.0.0.1:8000/api/v1';
$token = $env['BIZ_API_TOKEN_AGENT'] ?? '';
$api = new BusinessApiClient($baseUrl, $token);
$registry = new ToolRegistry();
foreach (BusinessTools::build($api) as $tool) {
    $registry->register($tool['name'], $tool['description'], $tool['inputSchema'], $tool['handler']);
}
$server = new McpServer($registry);

echo "MCP Server 冒烟测试\n";
echo "===================\n";

// ---------- 2. initialize ----------
$resp = $server->handle([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-03-26',
        'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'smoke-test', 'version' => '0.1'],
    ],
]);
$check($resp !== null && isset($resp['result']['protocolVersion']), 'initialize 握手');
$check(($resp['result']['protocolVersion'] ?? '') === McpServer::PROTOCOL_VERSION, '协议版本 ' . McpServer::PROTOCOL_VERSION);
$check(isset($resp['result']['capabilities']['tools']), '声明 tools capability');

// ---------- 3. notifications/initialized(通知无响应) ----------
$resp = $server->handle([
    'jsonrpc' => '2.0',
    'method' => 'notifications/initialized',
]);
$check($resp === null, 'notifications/initialized 无响应(通知)');

// ---------- 4. ping ----------
$resp = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);
$check(is_object($resp['result'] ?? null), 'ping');

// ---------- 5. tools/list ----------
$resp = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
$tools = $resp['result']['tools'] ?? [];
$check(count($tools) === 14, 'tools/list 返回 14 个工具(实际 ' . count($tools) . ')');
$names = array_column($tools, 'name');
$check(in_array('get_order', $names, true) && in_array('apply_refund', $names, true), '关键工具存在(get_order/apply_refund)');
$getOrderSchema = $tools[array_search('get_order', $names, true)]['inputSchema'] ?? [];
$check(($getOrderSchema['required'] ?? []) === ['order_no'], 'get_order 必填参数 order_no');

// ---------- 6. tools/call get_order(真实业务调用) ----------
$resp = $server->handle([
    'jsonrpc' => '2.0',
    'id' => 4,
    'method' => 'tools/call',
    'params' => ['name' => 'get_order', 'arguments' => ['order_no' => 'CE202608241027']],
]);
$content = $resp['result']['content'][0]['text'] ?? '';
$data = json_decode($content, true);
$check(($resp['result']['isError'] ?? true) === false, 'get_order 调用成功(isError=false)');
$check(($data['order_no'] ?? '') === 'CE202608241027', '返回订单号正确');

// ---------- 7. tools/call 参数缺失(工具层错误,isError=true) ----------
$resp = $server->handle([
    'jsonrpc' => '2.0',
    'id' => 5,
    'method' => 'tools/call',
    'params' => ['name' => 'get_order', 'arguments' => []],
]);
$check(($resp['result']['isError'] ?? false) === true, '缺少 order_no 返回 isError=true');

// ---------- 8. tools/call 不存在的工具 ----------
$resp = $server->handle([
    'jsonrpc' => '2.0',
    'id' => 6,
    'method' => 'tools/call',
    'params' => ['name' => 'no_such_tool', 'arguments' => []],
]);
$check(($resp['result']['isError'] ?? false) === true, '不存在工具返回 isError=true');

// ---------- 9. 未知方法 → JSON-RPC -32601 ----------
$resp = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'unknown/method']);
$check(($resp['error']['code'] ?? 0) === -32601, '未知方法返回 -32601');

// ---------- 10. 非法 JSON → -32700 ----------
$resp = $server->handle(null);
$check(($resp['error']['code'] ?? 0) === -32700, '解析失败返回 -32700');

echo "===================\n";
echo $fail === 0 ? "全部通过 ✅\n" : "失败 {$fail} 项 ❌\n";
exit($fail === 0 ? 0 : 1);
