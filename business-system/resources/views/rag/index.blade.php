@extends('layouts.admin')

@section('title', 'RAG 管理')
@section('page_sub', '知识库上传 / 重建索引 / 清空向量库')

@section('content')
    <h1>📚 RAG 管理</h1>
    <div class="sub">文档源 + 向量库状态；清空只删向量，源文档保留。</div>

    @if (session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif
    @if ($error)
        <div class="flash error">RAG 服务不可用：{{ $error }}</div>
    @endif

    @if ($status)
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:16px;">
            <div class="card" style="margin:0;">
                <div class="sub" style="margin:0;">Collection</div>
                <div style="font-size:18px; font-weight:600;">{{ $status['collection'] }}</div>
                <div class="sub" style="margin:4px 0 0; word-break:break-all;">{{ $status['persist_dir'] }}</div>
            </div>
            <div class="card" style="margin:0;">
                <div class="sub" style="margin:0;">Chunks</div>
                <div style="font-size:24px; font-weight:600;">{{ $status['chunks']['total'] }}</div>
                <div class="sub" style="margin:4px 0 0;">
                    agent {{ $status['chunks']['agent'] }} · admin {{ $status['chunks']['admin'] }}
                </div>
            </div>
            <div class="card" style="margin:0;">
                <div class="sub" style="margin:0;">文档</div>
                <div style="font-size:24px; font-weight:600;">{{ count($status['documents']) }}</div>
                <div class="sub" style="margin:4px 0 0;">
                    内置 {{ collect($status['documents'])->where('origin', 'builtin')->count() }} ·
                    上传 {{ collect($status['documents'])->where('origin', 'upload')->count() }}
                </div>
            </div>
            <div class="card" style="margin:0;">
                <div class="sub" style="margin:0;">状态</div>
                <div style="font-size:18px; font-weight:600; color:{{ $status['busy'] ? '#d97706' : '#059669' }};">
                    {{ $status['busy'] ? '处理中…' : '空闲' }}
                </div>
                @if ($status['last_operation'])
                    <div class="sub" style="margin:4px 0 0;">
                        {{ $status['last_operation']['action'] }} · {{ $status['last_operation']['at'] }}
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <h2 style="font-size:16px; margin:0 0 10px;">上传新文档</h2>
            <form method="POST" action="{{ url('/admin/rag/upload') }}" enctype="multipart/form-data">
                @csrf
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <input type="file" name="document" accept=".md" required>
                    <select name="access_level">
                        <option value="agent">客服 / 运营可见（agent）</option>
                        <option value="admin">仅管理员可见（admin）</option>
                    </select>
                    <button type="submit">上传并入库</button>
                </div>
                <div class="sub" style="margin:8px 0 0;">只支持 .md，≤2MB；同名文档会被拒绝。上传后立即增量入库。</div>
                @error('document') <div class="flash error" style="margin-top:8px;">{{ $message }}</div> @enderror
                @error('access_level') <div class="flash error" style="margin-top:8px;">{{ $message }}</div> @enderror
            </form>
        </div>

        <div class="card">
            <h2 style="font-size:16px; margin:0 0 10px;">文档列表</h2>
            <table>
                <thead>
                <tr><th>文件名</th><th>来源</th><th>权限</th><th>大小</th></tr>
                </thead>
                <tbody>
                @forelse ($status['documents'] as $doc)
                    <tr>
                        <td>{{ $doc['name'] }}</td>
                        <td>{{ $doc['origin'] === 'upload' ? '后台上传' : '内置' }}</td>
                        <td>
                            <span class="badge {{ $doc['access_level'] === 'admin' ? 'rejected' : 'approved' }}">
                                {{ $doc['access_level'] === 'admin' ? '仅管理员' : '客服可见' }}
                            </span>
                        </td>
                        <td>{{ number_format(($doc['size'] ?? 0) / 1024, 1) }} KB</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">暂无文档</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2 style="font-size:16px; margin:0 0 10px;">维护</h2>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <form method="POST" action="{{ url('/admin/rag/rebuild') }}"
                      onsubmit="return confirm('全量重建会重新嵌入所有文档，确认继续？');">
                    @csrf
                    <button type="submit">重建索引</button>
                </form>
                <form method="POST" action="{{ url('/admin/rag/clear') }}"
                      onsubmit="return confirm('确定清空向量库？源文档会保留，但需要重建才能恢复检索。');"
                      style="display:flex; gap:8px; align-items:center;">
                    @csrf
                    <input type="text" name="confirm" placeholder="输入 CLEAR 确认" required style="width:160px;">
                    <button type="submit" style="background:#dc2626;">清空向量库</button>
                </form>
            </div>
            <div class="sub" style="margin:8px 0 0;">重建约 15–30 秒；清空只删向量，源文档与权限映射保留。</div>
        </div>
    @endif
@endsection
