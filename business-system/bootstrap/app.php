<?php

use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'api.token' => ApiTokenAuth::class,
            'role' => \App\Http\Middleware\RequireRole::class,
        ]);

        // 全链路追踪：API 放最前面（尽早拿到/生成 trace_id 并注入日志上下文），
        // Web 追加在会话启动之后（terminate 时才能取到登录用户）。
        $middleware->api(prepend: [
            \App\Http\Middleware\TraceRequest::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\TraceRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
