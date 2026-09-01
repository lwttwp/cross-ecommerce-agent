<?php

declare(strict_types=1);

/**
 * stdio 端到端测试: 启动真实 mcp-server 进程,通过 stdin/stdout 管道通信。
 *
 * 用法:
 *   php tests/stdio_e2e.php
 *
 * 验证 MCP stdio 传输层: 换行分隔 JSON,逐行读写。
 */

$root = dirname(__DIR__);
$cmd = [PHP_BINARY, $root . '/bin/mcp-server.php'];

$proc = proc_open($cmd, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, $root);

if (!is_resource($proc)) {
    fwrite(STDERR, "无法启动 mcp-server 进程\n");
    exit(1);
}

// 非阻塞读辅助: 单条消息按行读取
$readLine = static function ($pipe, int $timeoutMs = 15000) {
    $line = '';
    $deadline = microtime(true) + $timeoutMs / 1000;
    stream_set_timeout($pipe, 1);
    while (microtime(true) < $deadline) {
        $chunk = fgets($pipe);
        if ($chunk === false) {
            $meta = stream_get_meta_data($pipe);
            if ($meta['timed_out']) {
                continue;
            }
            return null; // EOF
        }
        $line .= $chunk;
        if (str_ends_with($line, "\n")) {
            return trim($line);
        }
    }
    return null;
};

$send = static function (array $msg) use ($pipes): void {
    fwrite($pipes[0], json_encode($msg, JSON_UNESCAPED_UNICODE) . "\n");
    fflush($pipes[0]);
};

$fail = 0;
$check = static function (bool $cond, string $label) use (&$fail): void {
    echo ($cond ? "  ✅ " : "  ❌ ") . $label . "\n";
    if (!$cond) {
        $fail++;
    }
};

echo "stdio 端到端测试(进程管道)\n";
echo "===========================\n";

// 1. initialize
$send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
    'protocolVersion' => '2025-03-26',
    'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'e2e-test', 'version' => '0.1'],
]]);
$raw = $readLine($pipes[1]);
$resp = json_decode($raw ?? '', true);
$check(is_array($resp) && isset($resp['result']['serverInfo']['name']), 'initialize 经 stdio 返回');
$check(($resp['result']['serverInfo']['name'] ?? '') === 'cross-ecommerce-mcp', 'serverInfo.name 正确');

// 2. 通知不应产生响应: 发通知后立即发 tools/list,管道里第一条应是 list 的响应
$send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
$send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
$raw = $readLine($pipes[1]);
$resp = json_decode($raw ?? '', true);
$check(($resp['id'] ?? null) === 2 && isset($resp['result']['tools']), '通知无独立响应(list 响应正常到达)');
$tools = $resp['result']['tools'] ?? [];
$check(count($tools) === 14, 'tools/list 经 stdio 返回 14 个工具');

// 4. tools/call get_order(真实业务调用)
$send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
    'name' => 'get_order', 'arguments' => ['order_no' => 'CE202608241027'],
]]);
$resp = json_decode($readLine($pipes[1]) ?? '', true);
$content = $resp['result']['content'][0]['text'] ?? '';
$data = json_decode($content, true);
$check(($resp['result']['isError'] ?? true) === false, 'get_order 调用成功');
$check(($data['order_no'] ?? '') === 'CE202608241027', '返回订单号 CE202608241027');

// 5. 错误路径: 缺少必填参数
$send(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [
    'name' => 'get_order', 'arguments' => [],
]]);
$resp = json_decode($readLine($pipes[1]) ?? '', true);
$check(($resp['result']['isError'] ?? false) === true, '缺参数返回 isError=true');

// 6. 未知方法
$send(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'nope']);
$resp = json_decode($readLine($pipes[1]) ?? '', true);
$check(($resp['error']['code'] ?? 0) === -32601, '未知方法 -32601');

// 7. 写操作工具也验证一个(apply_refund 用不存在的订单,应返回业务错误而非崩溃)
$send(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => [
    'name' => 'apply_refund', 'arguments' => ['order_no' => 'CE202699999999', 'reason' => 'e2e 测试'],
]]);
$resp = json_decode($readLine($pipes[1]) ?? '', true);
$content = $resp['result']['content'][0]['text'] ?? '';
$check(($resp['result']['isError'] ?? false) === true, '不存在订单申请退款返回 isError=true');
$check(str_contains($content, '订单'), "错误信息可读: " . mb_substr($content, 0, 40));

// 关闭进程
fclose($pipes[0]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
proc_close($proc);

echo "===========================\n";
if ($fail === 0) {
    echo "全部通过 ✅\n";
} else {
    echo "失败 {$fail} 项 ❌\n";
    echo "--- stderr ---\n$stderr\n";
}
exit($fail === 0 ? 0 : 1);
