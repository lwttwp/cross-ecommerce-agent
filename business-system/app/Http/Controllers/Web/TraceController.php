<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TraceRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 全链路追踪查询页（仅管理员）。
 *
 * 解决的实际问题：以前排障要 docker logs 三个容器人肉对时间，
 * 现在按 trace_id 一查就能看到 chat → agent → http → queue → event 的每一跳。
 */
class TraceController extends Controller
{
    public function index(Request $request): View
    {
        $query = TraceRequest::query();

        $query->when($request->filled('trace_id'), function ($q) use ($request) {
            $q->where('trace_id', trim((string) $request->string('trace_id')));
        });

        $query->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')));

        $query->when($request->filled('only_error'), fn ($q) => $q->where('status', '>=', 400));

        $query->when($request->filled('date_from'), function ($q) use ($request) {
            $q->where('created_at', '>=', $request->date('date_from')?->startOfDay());
        });

        $query->when($request->filled('date_to'), function ($q) use ($request) {
            $q->where('created_at', '<=', $request->date('date_to')?->endOfDay());
        });

        $spans = $query->orderByDesc('id')->paginate(30)->withQueryString();

        // 顶部小盘：近 24h 的错误数与链路数
        $since = now()->subDay();
        $summary = [
            'spans' => TraceRequest::where('created_at', '>=', $since)->count(),
            'errors' => TraceRequest::where('created_at', '>=', $since)->where('status', '>=', 400)->count(),
            'traces' => TraceRequest::where('created_at', '>=', $since)->distinct('trace_id')->count('trace_id'),
            'audits' => AuditLog::where('created_at', '>=', $since)->count(),
        ];

        return view('admin.traces', ['spans' => $spans, 'summary' => $summary]);
    }

    public function show(string $traceId): View
    {
        $spans = TraceRequest::where('trace_id', $traceId)->orderBy('id')->get();
        $audits = AuditLog::where('trace_id', $traceId)->orderBy('id')->get();

        abort_if($spans->isEmpty(), 404, '未找到该 trace_id 的链路记录');

        return view('admin.trace_show', ['traceId' => $traceId, 'spans' => $spans, 'audits' => $audits]);
    }
}
