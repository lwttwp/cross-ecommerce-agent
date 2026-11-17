@extends('layouts.admin')

@section('title', '用户管理')
@section('page_sub', '新增、禁用/启用、软删除')

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
            <h1 style="margin:0;">👥 用户管理</h1>
            <div class="sub" style="margin:4px 0 0;">共 {{ $users->total() }} 个用户</div>
        </div>
        <a href="{{ url('/admin/users/create') }}"
           style="background:#059669; color:#fff; padding:9px 18px; border-radius:8px; text-decoration:none; font-size:14px;">＋ 新增用户</a>
    </div>

    @if (session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    <form class="filters" method="GET">
        <select name="role">
            <option value="">全部角色</option>
            <option value="agent" @selected(request('role') === 'agent')>客服 / 运营</option>
            <option value="admin" @selected(request('role') === 'admin')>管理员</option>
        </select>
        <select name="status">
            <option value="">全部状态</option>
            @foreach (\App\Enums\UserStatus::cases() as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="姓名 / 邮箱">
        <button type="submit">筛选</button>
        <a class="reset" href="{{ url('/admin/users') }}">重置</a>
    </form>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>ID</th><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th><th>创建时间</th><th>操作</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->id }}</td>
                    <td>
                        {{ $user->name }}
                        @if ($user->id === auth()->id())
                            <small>（当前账号）</small>
                        @endif
                    </td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->role === 'admin' ? '管理员' : '客服 / 运营' }}</td>
                    <td>
                        <span class="badge {{ $user->status === \App\Enums\UserStatus::Active ? 'approved' : 'rejected' }}">
                            {{ $user->status->label() }}
                        </span>
                    </td>
                    <td class="nowrap">{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="nowrap">
                        @if ($user->id === auth()->id())
                            <small>当前账号</small>
                        @else
                            <form method="POST" action="{{ url("/admin/users/{$user->id}/toggle") }}" style="display:inline;">
                                @csrf
                                <button type="submit"
                                        style="background:{{ $user->status === \App\Enums\UserStatus::Active ? '#d97706' : '#059669' }}; padding:4px 12px; font-size:13px;">
                                    {{ $user->status === \App\Enums\UserStatus::Active ? '禁用' : '启用' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ url("/admin/users/{$user->id}") }}"
                                  style="display:inline; margin-left:6px;"
                                  onsubmit="return confirm('确认删除该用户？软删除后历史数据保留。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="background:#dc2626; padding:4px 12px; font-size:13px;">删除</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">没有符合条件的用户</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $users->links('pagination.custom') }}</div>
@endsection
