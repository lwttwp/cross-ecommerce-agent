@extends('layouts.admin')

@section('title', '新增用户')
@section('page_sub', '创建后台账号')

@section('content')
    <h1>👥 新增用户</h1>
    <div class="sub">账号创建后即可登录后台；禁用/删除在列表页操作。</div>

    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    <div class="card" style="max-width:640px;">
        <form method="POST" action="{{ url('/admin/users') }}">
            @csrf
            <div style="display:grid; gap:14px;">
                <label>姓名
                    <input type="text" name="name" value="{{ old('name') }}" required maxlength="64" style="width:100%;">
                    @error('name') <small style="color:#dc2626;">{{ $message }}</small> @enderror
                </label>
                <label>邮箱
                    <input type="email" name="email" value="{{ old('email') }}" required maxlength="128" style="width:100%;">
                    @error('email') <small style="color:#dc2626;">{{ $message }}</small> @enderror
                </label>
                <label>初始密码
                    <input type="password" name="password" required minlength="8" maxlength="72" style="width:100%;">
                    @error('password') <small style="color:#dc2626;">{{ $message }}</small> @enderror
                </label>
                <label>角色
                    <select name="role" required style="width:100%;">
                        <option value="agent" @selected(old('role', 'agent') === 'agent')>客服 / 运营（agent）</option>
                        <option value="admin" @selected(old('role') === 'admin')>管理员（admin）</option>
                    </select>
                    @error('role') <small style="color:#dc2626;">{{ $message }}</small> @enderror
                </label>
                <div style="display:flex; gap:8px;">
                    <button type="submit">创建用户</button>
                    <a href="{{ url('/admin/users') }}" style="align-self:center; color:#2563eb; font-size:13px; text-decoration:none;">取消</a>
                </div>
            </div>
        </form>
    </div>

    <style>
        label { display: block; font-size: 13px; color: #374151; }
        input[type=email], input[type=password] {
            padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            background: #fff; font-size: 14px;
        }
    </style>
@endsection
