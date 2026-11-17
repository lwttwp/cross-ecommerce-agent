<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TraceRequest;
use App\Support\ApiResponse;
use App\Support\TraceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Agent 侧链路上报（内部接口，api.token:agent）。
 *
 * 为什么由 Agent 上报而不是直接写库：业务系统是数据的唯一写入方，
 * Agent 不直连数据库（三层不变量第 1 条）。Agent 每完成一轮对话就 POST 一条，
 * 失败只影响可观测性，不影响对话。
 */
class TraceIngestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // 与 TraceRequest 共用逃生阀：TRACE_ENABLED=false 时整体停写，不是只关 HTTP 中间件
        if (! config('observability.enabled')) {
            return ApiResponse::ok(['accepted' => false]);
        }

        $data = $request->validate([
            'trace_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,40}$/'],
            'span' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'integer', 'min:0', 'max:599'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'route' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'user_name' => ['nullable', 'string', 'max:64'],
            'role' => ['nullable', 'string', 'in:agent,admin'],
            'meta' => ['nullable', 'array'],
        ]);

        try {
            TraceRequest::create([
                'trace_id' => $data['trace_id'],
                'span' => $data['span'] ?? 'agent.round',
                'channel' => 'agent',
                'method' => null,
                'path' => 'agent:chat',
                'route' => $data['route'] ?? null,
                'status' => $data['status'] ?? 200,
                'duration_ms' => $data['duration_ms'] ?? null,
                'user_id' => $data['user_id'] ?? TraceContext::userId(),
                'user_name' => $data['user_name'] ?? TraceContext::userName(),
                'role' => $data['role'] ?? TraceContext::role(),
                'ip' => $request->ip(),
                'session_id' => $data['session_id'] ?? null,
                'meta' => isset($data['meta']) ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('agent span 落库失败', ['error' => $e->getMessage()]);

            return ApiResponse::fail(50001, '链路写入失败', 500);
        }

        return ApiResponse::ok(['accepted' => true]);
    }
}
