<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TraceContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 全链路追踪中间件（Web + API 共用）。
 *
 * 职责：
 * 1. 采用上游 X-Trace-Id（Agent / MCP 传入），没有则本地生成；
 * 2. 把 trace_id 注入日志上下文 —— 业务代码不用改就能带上链路 id；
 * 3. API 请求采信 Agent 透传的 X-User-*；Web 请求以登录 session 用户为准（防止头伪造）；
 * 4. 响应头回写 X-Trace-Id，便于前端/排障反查；
 * 5. terminate 阶段落一行 trace_requests（异步于响应，不拖慢接口）。
 *
 * 失败一律吞掉：观测链路不能反过来影响业务。
 */
class TraceRequest
{
    /** 静态资源与健康检查不落库，避免噪音 */
    private const IGNORED_PATHS = ['up', 'api/v1/health', 'api/v1/internal/traces', 'favicon.ico', 'robots.txt'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observability.enabled')) {
            return $next($request);
        }

        $traceId = TraceContext::adopt((string) $request->header('X-Trace-Id', ''));

        // Web 请求以登录 session 为准；X-User-* 只用于 API 机器调用（Agent / MCP），
        // 且必须是正整数员工 id —— 0 是本地调试占位，不能落审计/链路。
        $sessionUser = $request->user();
        if ($sessionUser !== null) {
            TraceContext::setUserFromModel($sessionUser);
        } elseif ($request->is('api/*')) {
            $headerUserId = (string) $request->header('X-User-Id', '');
            if ($headerUserId !== '' && ctype_digit($headerUserId) && (int) $headerUserId > 0) {
                $role = (string) $request->header('X-User-Role', 'agent');
                TraceContext::setUser([
                    'id' => (int) $headerUserId,
                    'name' => $this->sanitizeName((string) $request->header('X-User-Name', 'Agent')),
                    'role' => in_array($role, ['agent', 'admin'], true) ? $role : 'agent',
                ]);
            }
        }

        $this->applyLogContext($traceId);

        $startedAt = hrtime(true);

        $response = $next($request);

        $response->headers->set('X-Trace-Id', $traceId);

        // 供 terminate 使用（terminate 拿不到闭包里的 $startedAt）
        $request->attributes->set('_trace_started_ns', $startedAt);

        return $response;
    }

    /** 响应发出后写链路，任何异常都只记日志，不影响请求结果 */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('observability.enabled')) {
            return;
        }

        try {
            if ($this->shouldSkip($request)) {
                return;
            }

            // 未经 token 鉴权的 API 请求不落库：否则匿名流量可无限写 trace_requests，
            // 还能自带 X-Trace-Id/X-User-* 污染排障视图。鉴权失败的告警由 ApiTokenAuth 负责。
            if ($request->is('api/*') && $request->attributes->get('api_role') === null) {
                return;
            }

            // 兜底：handle() 正常已按 session 填好用户；若因中间件顺序变化仍为空，这里再补一次
            if (TraceContext::user() === null && $request->user() !== null) {
                TraceContext::setUserFromModel($request->user());
                $this->applyLogContext(TraceContext::id());
            }

            $startedAt = (int) $request->attributes->get('_trace_started_ns', 0);
            $durationMs = $startedAt > 0 ? (int) round((hrtime(true) - $startedAt) / 1_000_000) : null;

            DB::table('trace_requests')->insert([
                'trace_id' => TraceContext::id(),
                'span' => 'http.request',
                'channel' => 'http',
                'method' => $request->getMethod(),
                'path' => mb_substr($request->path(), 0, 500),
                'route' => $this->routeName($request),
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'user_id' => TraceContext::userId(),
                'user_name' => TraceContext::userName(),
                'role' => TraceContext::role(),
                'ip' => $request->ip(),
                'session_id' => null,
                'meta' => json_encode([
                    'api_role' => $request->attributes->get('api_role'),
                    'ajax' => $request->ajax(),
                    'trace_origin' => TraceContext::origin(),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (QueryException|Throwable $e) {
            Log::warning('trace 落库失败', ['error' => $e->getMessage()]);
        }
    }

    private function shouldSkip(Request $request): bool
    {
        // 下载、静态资源、健康检查不记
        $path = trim($request->path(), '/');

        foreach (self::IGNORED_PATHS as $ignored) {
            if ($path === $ignored) {
                return true;
            }
        }

        // 静态资源（css/js/图片/字体）不产生链路，避免把表写爆
        if (preg_match('/\.(css|js|map|png|jpe?g|gif|svg|ico|woff2?|ttf)$/i', $path) === 1) {
            return true;
        }

        return $request->is('admin/tasks/*/download');
    }

    private function routeName(Request $request): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $name = $route->getName();

        if ($name !== null) {
            return mb_substr($name, 0, 120);
        }

        $action = $route->getActionName();

        return is_string($action) ? mb_substr($action, 0, 120) : null;
    }

    private function sanitizeName(string $name): string
    {
        // 去掉控制字符与换行，防止日志注入
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return mb_substr(trim($name), 0, 64) ?: 'Agent';
    }

    private function applyLogContext(string $traceId): void
    {
        Log::withContext(array_filter([
            'trace_id' => $traceId,
            'user_id' => TraceContext::userId(),
            'role' => TraceContext::role(),
            'trace_origin' => TraceContext::origin(),
        ], static fn ($v) => $v !== null));
    }
}
