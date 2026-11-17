<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 角色守卫：`role:admin`。
 *
 * 多用户上线后，后台页面默认只要求登录；资金审批与链路查询必须限定管理员。
 * API 侧不走本中间件（那边由 api.token:admin 的独立 token 把守）。
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $role = 'admin'): Response
    {
        $user = $request->user();

        if ($user === null || ($user->role ?? null) !== $role) {
            abort(403, '无权访问：需要管理员权限');
        }

        return $next($request);
    }
}
