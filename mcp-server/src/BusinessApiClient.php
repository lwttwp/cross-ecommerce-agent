<?php

declare(strict_types=1);

namespace Mcp;

/**
 * 业务系统 REST 客户端(零依赖,基于 curl)。
 * 统一鉴权(Bearer Token)、超时、响应解析。
 * 失败不抛异常,返回 ["error" => ...] 交给上层转成 MCP 工具错误,保证 LLM 可读。
 */
final class BusinessApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeout = 10.0,
    ) {
    }

    public function get(string $path, array $params = []): array
    {
        if ($params !== []) {
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($params);
        }
        return $this->request('GET', $path);
    }

    public function post(string $path, ?array $json = null): array
    {
        return $this->request('POST', $path, $json);
    }

    public function put(string $path, ?array $json = null): array
    {
        return $this->request('PUT', $path, $json);
    }

    /** 原始文本(用于 CSV 下载),失败抛 RuntimeException。 */
    public function getText(string $path): string
    {
        $resp = $this->raw('GET', $path);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException("HTTP {$resp['status']}: {$resp['body']}");
        }
        return $resp['body'];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $resp = $this->raw($method, $path, $json);
        $body = json_decode($resp['body'], true);
        if (!is_array($body)) {
            return ['error' => "HTTP {$resp['status']}: 非 JSON 响应"];
        }
        if (($body['code'] ?? -1) !== 0) {
            return ['error' => $body['message'] ?? "HTTP {$resp['status']}"];
        }
        return $body['data'] ?? [];
    }

    /** @return array{status: int, body: string} */
    private function raw(string $method, string $path, ?array $json = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $headers = ['Authorization: Bearer ' . $this->token];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => json_encode(['code' => -1, 'message' => "curl 错误: {$err}"])];
        }
        return ['status' => $status, 'body' => (string) $body];
    }
}
