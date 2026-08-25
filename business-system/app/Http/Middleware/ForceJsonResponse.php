<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 强制 /api/* 请求按 JSON 响应处理。
 * 否则校验失败时 Laravel 会 302 重定向回首页（表单逻辑），API 场景应返回 422 JSON。
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
