<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * RAG 管理（仅管理员）：状态、上传文档、重建索引、清空向量库。
 *
 * 真正的文件/向量操在 chat 服务里；这里只转发浏览器 Cookie 并落审计。
 */
class RagController extends Controller
{
    public function index(Request $request): View
    {
        try {
            $status = $this->call($request, 'get', '/api/rag/status', [], 10);
            $error = null;
        } catch (Throwable $e) {
            $status = null;
            $error = $e->getMessage();
        }

        return view('rag.index', ['status' => $status, 'error' => $error]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'document' => ['required', 'file', 'max:2048', 'extensions:md'],
            'access_level' => ['required', Rule::in(['agent', 'admin'])],
        ]);

        $file = $request->file('document');

        try {
            $result = $this->call($request, 'post', '/api/rag/upload', [
                'filename' => $file->getClientOriginalName(),
                'content' => (string) $file->get(),
                'access_level' => $data['access_level'],
            ], 60);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', '上传失败：'.$e->getMessage());
        }

        AuditLogger::log('rag.upload', 'rag', (string) ($result['filename'] ?? $file->getClientOriginalName()), [
            'access_level' => $data['access_level'],
            'chunks_added' => $result['chunks_added'] ?? null,
        ]);

        return back()->with('success', "文档已上传并增量入库（新增 {$result['chunks_added']} 个 chunk）");
    }

    public function rebuild(Request $request): RedirectResponse
    {
        try {
            $result = $this->call($request, 'post', '/api/rag/rebuild', [], 180);
        } catch (Throwable $e) {
            return back()->with('error', '重建失败：'.$e->getMessage());
        }

        AuditLogger::log('rag.rebuild', 'rag', null, [
            'chunks' => $result['chunks'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
        ]);

        return back()->with('success', "重建完成：{$result['chunks']} 个 chunk，耗时 {$result['duration_ms']} ms");
    }

    public function clear(Request $request): RedirectResponse
    {
        $data = $request->validate(['confirm' => ['required', 'string']]);
        if ($data['confirm'] !== 'CLEAR') {
            return back()->with('error', '确认词不正确，请输入 CLEAR');
        }

        try {
            $result = $this->call($request, 'post', '/api/rag/clear', ['confirm' => 'CLEAR'], 30);
        } catch (Throwable $e) {
            return back()->with('error', '清空失败：'.$e->getMessage());
        }

        AuditLogger::log('rag.clear', 'rag', null, ['deleted' => $result['deleted'] ?? null]);

        return back()->with('success', "已清空 {$result['deleted']} 个 chunk（源文档保留，可重建恢复）");
    }

    /**
     * 调 chat 内部接口，转发浏览器 Cookie（chat 会用它回查 /session/me）。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(Request $request, string $method, string $path, array $payload = [], int $timeout = 15): array
    {
        $base = rtrim((string) config('services.chat.base_url'), '/');
        $http = Http::withHeaders([
            'Cookie' => (string) $request->header('Cookie'),
            'Accept' => 'application/json',
        ])->timeout($timeout);

        $response = $method === 'get'
            ? $http->get($base.$path)
            : $http->post($base.$path, $payload);

        if (! $response->successful()) {
            $message = $response->json('detail')
                ?? $response->json('message')
                ?? ('RAG 服务返回 HTTP '.$response->status());

            throw new \RuntimeException((string) $message);
        }

        $json = $response->json();
        if (! is_array($json) || ($json['code'] ?? null) !== 0) {
            throw new \RuntimeException(
                is_array($json) ? (string) ($json['message'] ?? 'RAG 服务返回格式异常') : 'RAG 服务返回格式异常'
            );
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
