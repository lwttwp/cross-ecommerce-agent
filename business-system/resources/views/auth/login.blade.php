<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 - 跨境电商智能订单助手</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f3f4f6; color: #1f2937;
               min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .box { background: #fff; border-radius: 12px; padding: 36px 40px; width: 340px;
               box-shadow: 0 4px 16px rgba(0,0,0,.08); }
        .logo { font-size: 26px; text-align: center; margin-bottom: 4px; }
        h1 { font-size: 18px; text-align: center; margin: 0 0 6px; }
        .sub { text-align: center; color: #6b7280; font-size: 13px; margin-bottom: 24px; }
        label { display: block; font-size: 13px; color: #374151; margin: 14px 0 6px; }
        input[type=email], input[type=password] {
            width: 100%; box-sizing: border-box; padding: 10px 12px;
            border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;
        }
        input:focus { border-color: #2563eb; }
        button { width: 100%; margin-top: 22px; padding: 11px; background: #2563eb; color: #fff;
                 border: 0; border-radius: 8px; font-size: 15px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .error { background: #fee2e2; color: #991b1b; font-size: 13px; padding: 9px 12px;
                 border-radius: 8px; margin-top: 16px; }
        .hint { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 18px; }
    </style>
</head>
<body>
<div class="box">
    <div class="logo">🛒</div>
    <h1>跨境电商智能订单助手</h1>
    <div class="sub">后台管理系统</div>

    <form method="POST" action="{{ url('/login') }}">
        @csrf
        <label>邮箱</label>
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label>密码</label>
        <input type="password" name="password" required autocomplete="current-password">

        <button type="submit">登 录</button>
    </form>

    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <div class="hint">演示账号：admin@example.com / admin123</div>
</div>
</body>
</html>
