<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '后台') - 跨境电商智能订单助手</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f3f4f6; color: #1f2937; display: flex; min-height: 100vh; }
        .sidebar { width: 200px; background: #111827; color: #d1d5db; padding: 18px 0; flex-shrink: 0; }
        .sidebar .brand { font-size: 15px; font-weight: 600; color: #fff; padding: 4px 20px 16px; }
        .sidebar .brand small { display: block; color: #6b7280; font-weight: 400; font-size: 11px; margin-top: 2px; }
        .sidebar a { display: block; padding: 10px 20px; color: #d1d5db; text-decoration: none; font-size: 13.5px; }
        .sidebar a:hover { background: #1f2937; color: #fff; }
        .sidebar a.active { background: #2563eb; color: #fff; }
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .topbar { background: #fff; padding: 12px 24px; display: flex; justify-content: space-between;
                  align-items: center; border-bottom: 1px solid #e5e7eb; }
        .topbar .user { font-size: 13px; color: #374151; display: flex; align-items: center; gap: 12px; }
        .topbar form { margin: 0; }
        .topbar .logout { font-size: 13px; color: #dc2626; background: none; border: 0; cursor: pointer; text-decoration: none; }
        .content { padding: 24px; flex: 1; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #6b7280; font-size: 13px; margin-bottom: 16px; }
        form.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        select, input[type=text] {
            padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            background: #fff; font-size: 14px;
        }
        button { padding: 7px 18px; background: #2563eb; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { background: #1d4ed8; }
        .reset { align-self: center; color: #2563eb; font-size: 13px; text-decoration: none; }
        .card { background: #fff; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; font-size: 13.5px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 9px 8px; text-align: left; }
        th { background: #f9fafb; color: #374151; font-weight: 600; }
        td small { color: #9ca3af; }
        td.nowrap { white-space: nowrap; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
        .badge.pending-payment, .badge.pending { background: #fef3c7; color: #92400e; }
        .badge.paid, .badge.approved, .badge.success { background: #d1fae5; color: #065f46; }
        .badge.shipped { background: #dbeafe; color: #1e40af; }
        .badge.completed, .badge.running { background: #e0e7ff; color: #3730a3; }
        .badge.cancelled { background: #f3f4f6; color: #4b5563; }
        .badge.refunding { background: #fce7f3; color: #9d174d; }
        .badge.refunded, .badge.rejected, .badge.failed { background: #fee2e2; color: #991b1b; }
        .pagination { margin-top: 16px; display: flex; gap: 4px; flex-wrap: wrap; }
        .pagination a, .pagination span {
            padding: 4px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            text-decoration: none; color: #2563eb; font-size: 13px; background: #fff;
        }
        .pagination .disabled { color: #9ca3af; }
        .pagination a.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .empty { text-align: center; color: #9ca3af; padding: 40px 0; }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 14px; }
        .flash.success { background: #d1fae5; color: #065f46; }
        .flash.error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
<nav class="sidebar">
    <div class="brand">🛒 订单助手<small>后台管理</small></div>
    <a href="{{ url('/admin') }}" class="{{ request()->is('admin') ? 'active' : '' }}">📊 仪表盘</a>
    <a href="{{ url('/admin/orders') }}" class="{{ request()->is('admin/orders*') ? 'active' : '' }}">📦 订单管理</a>
    <a href="{{ url('/admin/customers') }}" class="{{ request()->is('admin/customers*') ? 'active' : '' }}">👤 客户管理</a>
    <a href="{{ url('/admin/products') }}" class="{{ request()->is('admin/products*') ? 'active' : '' }}">🏷️ 商品管理</a>
    <a href="{{ url('/admin/refunds') }}" class="{{ request()->is('admin/refunds*') ? 'active' : '' }}">💸 退款审批</a>
    <a href="{{ url('/admin/tasks') }}" class="{{ request()->is('admin/tasks*') ? 'active' : '' }}">⚙️ 异步任务</a>
    <a href="{{ url('/admin/rates') }}" class="{{ request()->is('admin/rates*') ? 'active' : '' }}">💱 汇率配置</a>
</nav>

<div class="main">
    <div class="topbar">
        <div class="sub" style="margin:0;">@yield('page_sub', '')</div>
        <div class="user">
            <span>👤 {{ auth()->user()?->name }}（{{ auth()->user()?->role }}）</span>
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <button type="submit" class="logout">退出登录</button>
            </form>
        </div>
    </div>
    <div class="content">
        @yield('content')
    </div>
</div>
</body>
</html>
