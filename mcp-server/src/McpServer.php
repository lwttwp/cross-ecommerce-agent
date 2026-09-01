<?php

declare(strict_types=1);

namespace Mcp;

/**
 * MCP Server 核心:JSON-RPC 2.0 分发。
 * 协议版本 2025-03-26,支持 stdio 换行分隔 JSON。
 *
 * 已实现方法:
 *   initialize / notifications/initialized / ping
 *   tools/list / tools/call
 *   resources/list(空) / prompts/list(空)
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2025-03-26';

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly string $serverName = 'cross-ecommerce-mcp',
        private readonly string $serverVersion = '1.0.0',
    ) {
    }

    /**
     * 处理一条消息,返回待写回的响应;通知(无 id)返回 null。
     *
     * @param array<string, mixed>|null $msg json_decode 结果;null 表示 JSON 解析失败
     * @return array<string, mixed>|null
     */
    public function handle(?array $msg): ?array
    {
        if ($msg === null) {
            return $this->error(null, -32700, 'Parse error');
        }
        if (($msg['jsonrpc'] ?? '') !== '2.0' || !isset($msg['method']) || !is_string($msg['method'])) {
            return $this->error($msg['id'] ?? null, -32600, 'Invalid Request');
        }

        $id = $msg['id'] ?? null;
        $isNotification = !array_key_exists('id', $msg);
        $params = $msg['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        try {
            $result = match ($msg['method']) {
                'initialize' => $this->initialize($params),
                'notifications/initialized' => null,
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->registry->all()],
                'tools/call' => $this->callTool($params),
                'resources/list' => ['resources' => []],
                'prompts/list' => ['prompts' => []],
                default => throw new \RuntimeException('Method not found', -32601),
            };
        } catch (\Throwable $e) {
            $code = $e->getCode() !== 0 ? $e->getCode() : -32603;
            return $this->error($id, $code, $e->getMessage());
        }

        if ($result === null || $isNotification) {
            return null;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @param array<string, mixed> $params */
    private function initialize(array $params): array
    {
        // 可选: 校验客户端请求的 protocolVersion 是否兼容
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ];
    }

    /** @param array<string, mixed> $params */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || $name === '') {
            throw new \RuntimeException('缺少工具名 name', -32602);
        }
        if (!is_array($arguments)) {
            throw new \RuntimeException('arguments 必须是对象', -32602);
        }
        return $this->registry->call($name, $arguments);
    }

    /**
     * @param array<string, mixed>|null $id
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
