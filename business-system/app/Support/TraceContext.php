<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 一次请求 / 一次队列执行的链路上下文（trace_id + 操作人）。
 *
 * 为什么用静态类而不是容器绑定：
 * - 中间件、控制器、Service、Job 里都要读，静态访问最省事；
 * - 单次请求进程内只有一个上下文，Laravel 传统 PHP-FPM 模型下无并发共享问题；
 * - 队列 Job 必须先 reset() 再 set()，否则会继承派发方的上下文。
 */
final class TraceContext
{
    private static ?string $traceId = null;

    /** upstream=采用上游 X-Trace-Id；local=本地兜底生成 */
    private static string $origin = 'local';

    /** @var array{id: int|string|null, name: string|null, role: string|null}|null */
    private static ?array $user = null;

    public static function id(): string
    {
        return self::$traceId ??= self::generate();
    }

    public static function setTraceId(?string $traceId): void
    {
        self::$traceId = $traceId;
        self::$origin = ($traceId !== null && $traceId !== '') ? 'upstream' : 'local';
    }

    /** trace_id 来源：upstream（上游头）或 local（本地兜底） */
    public static function origin(): string
    {
        return self::$origin;
    }

    /**
     * 采用上游传来的 trace_id（agent 的 X-Trace-Id），不合法则本地生成。
     * 只接受 8-40 位字母数字与 - _，避免日志注入。
     */
    public static function adopt(?string $candidate): string
    {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9_-]{8,40}$/', $candidate) === 1) {
            self::$traceId = $candidate;
            self::$origin = 'upstream';

            return $candidate;
        }

        self::$traceId = self::generate();

        return self::$traceId;
    }

    public static function generate(): string
    {
        self::$origin = 'local';

        return bin2hex(random_bytes(8));   // 16 位十六进制，够用且短
    }

    /** @return array{id: int|string|null, name: string|null, role: string|null}|null */
    public static function user(): ?array
    {
        return self::$user;
    }

    public static function userId(): ?int
    {
        return isset(self::$user['id']) && self::$user['id'] !== null ? (int) self::$user['id'] : null;
    }

    public static function userName(): ?string
    {
        return self::$user['name'] ?? null;
    }

    public static function role(): ?string
    {
        return self::$user['role'] ?? null;
    }

    /** @param array{id: int|string|null, name: string|null, role: string|null}|null $user */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    /** 由 Eloquent User 模型填充上下文 */
    public static function setUserFromModel(?object $user): void
    {
        if ($user === null) {
            return;
        }

        self::$user = [
            'id' => $user->id ?? null,
            'name' => $user->name ?? null,
            'role' => $user->role ?? null,
        ];
    }

    /** 队列 Job 执行前清理，避免继承派发方上下文 */
    public static function reset(): void
    {
        self::$traceId = null;
        self::$origin = 'local';
        self::$user = null;
    }
}
