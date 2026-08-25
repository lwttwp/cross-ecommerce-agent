<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Token 鉴权中间件。
 * 用法：api.token        -> 任意已配置角色
 *       api.token:admin  -> 仅 admin 角色
 */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next, string $role = 'agent'): Response
    {
        $token = $request->bearerToken();
        $tokens = (array) config('api.tokens', []);

        if ($token === null || ! isset($tokens[$role]) || $tokens[$role] === '' || ! hash_equals($tokens[$role], $token)) {
            return response()->json([
                'code' => 40100,
                'message' => '未认证或 Token 无效',
                'data' => null,
            ], 401);
        }

        $request->attributes->set('api_role', $role);

        return $next($request);
    }
}
