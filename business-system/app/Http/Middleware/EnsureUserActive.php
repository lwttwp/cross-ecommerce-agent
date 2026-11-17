<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台登录态活性校验：账号被禁用或软删后，下一次请求立即失效。
 *
 * 挂在 `auth` 之后；未登录的请求不会走到这里（auth 先重定向）。
 */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 软删用户被 Eloquent 全局作用域过滤成 null；禁用用户仍能取到但状态不对
        if ($user === null || $user->status !== UserStatus::Active) {
            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect('/login')->with('error', '账号已被禁用，请联系管理员');
        }

        return $next($request);
    }
}
