<?php

declare(strict_types=1);

namespace Mcp;

/**
 * 工具注册表:登记、列出(tools/list)、执行(tools/call)。
 */
final class ToolRegistry
{
    /**
     * @var array<string, array{
     *   name: string,
     *   description: string,
     *   inputSchema: array<string, mixed>,
     *   handler: callable
     * }>
     */
    private array $tools = [];

    /**
     * @param array<string, mixed> $inputSchema JSON Schema(object)
     */
    public function register(string $name, string $description, array $inputSchema, callable $handler): void
    {
        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler' => $handler,
        ];
    }

    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function all(): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            $list[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }
        return $list;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * 执行工具,返回 MCP tools/call 结果结构。
     *
     * @param array<string, mixed> $arguments
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function call(string $name, array $arguments): array
    {
        if (!$this->has($name)) {
            return $this->text('工具不存在: ' . $name, true);
        }
        // 通用必填参数校验: 非法参数在进入业务系统前被拦截
        $tool = $this->tools[$name];
        $missing = [];
        foreach ($tool['inputSchema']['required'] ?? [] as $key) {
            if (!array_key_exists($key, $arguments) || $arguments[$key] === null) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            return $this->text('缺少必填参数: ' . implode(', ', $missing), true);
        }
        try {
            $result = ($this->tools[$name]['handler'])($arguments);
            // 业务层错误约定: 返回数组含 error 键 → 标记 isError,LLM 能读到原因
            if (is_array($result) && isset($result['error'])) {
                return $this->text(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), true);
            }
            return $this->text(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), false);
        } catch (\Throwable $e) {
            return $this->text('工具执行异常: ' . $e->getMessage(), true);
        }
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private function text(string $text, bool $isError): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }
}
