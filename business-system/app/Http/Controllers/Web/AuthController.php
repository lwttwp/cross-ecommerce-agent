<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 后台登录（session 认证，区别于 API 的 token 鉴权）。
 */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attemptWhen(
            $credentials,
            static fn (User $user): bool => $user->status === UserStatus::Active,
            $request->boolean('remember'),
        )) {
            $request->session()->regenerate();

            // 支持登录后回到原页面（如 /chat/ 未登录被踢回登录页的场景）。
            // 只接受站内绝对路径，避免开放重定向。
            $target = (string) $request->query('redirect', '');
            if ($target === '' || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
                $target = '/admin';
            }

            return redirect()->intended($target);
        }

        return back()
            ->withErrors(['email' => '邮箱或密码错误'])
            ->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
