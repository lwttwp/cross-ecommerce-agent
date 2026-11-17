<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Token 鉴权中间件。
 * 用法：api.token        -> 任意已配置角色
 *       api.token:admin  -> 仅 admin 角色
 *
 * 多用户说明：token 仍然只区分「机器角色」（agent 后端 / admin 后端），
 * 具体是哪个员工，由 Agent 透传的 X-User-Id / X-User-Name 决定，
 * 由 TraceContext 承接并写入审计与链路；不在这里做员工级鉴权，
 * 避免业务系统耦合 Agent 的账号体系（保持"业务系统不感知 AI"）。
 */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next, string $role = 'agent'): Response
    {
        $token = $request->bearerToken();
        // 下载链接场景: 浏览器无法带 Authorization 头,允许 GET 用 ?token= 查询参数
        // (仅 GET;写操作仍强制 header,避免 token 经 URL 用于状态变更)
        if ($token === null && $request->isMethod('GET')) {
            $token = $request->query('token');
        }
        $tokens = (array) config('api.tokens', []);

        if ($token === null || ! isset($tokens[$role]) || $tokens[$role] === '' || ! hash_equals($tokens[$role], $token)) {
            Log::warning('api 鉴权失败', [
                'role' => $role,
                'ip' => $request->ip(),
                'path' => $request->path(),
                'has_token' => $token !== null,
            ]);

            return response()->json([
                'code' => 40100,
                'message' => '未认证或 Token 无效',
                'data' => null,
            ], 401);
        }

        $request->attributes->set('api_role', $role);

        // 没有上游员工身份时，退化成机器身份，便于审计里区分「人操作」与「定时/脚本操作」
        if (TraceContext::user() === null) {
            TraceContext::setUser([
                'id' => null,
                'name' => 'api:'.$role,
                'role' => $role,
            ]);
        }

        // 文档契约：api_user 是本次调用的操作者上下文（员工身份或机器兜底）
        $request->attributes->set('api_user', TraceContext::user());

        // TraceRequest 在 token 校验前已注入过日志上下文；这里补上机器兜底身份
        Log::withContext(array_filter([
            'user_id' => TraceContext::userId(),
            'role' => TraceContext::role(),
        ], static fn ($v) => $v !== null));

        return $next($request);
    }
}
