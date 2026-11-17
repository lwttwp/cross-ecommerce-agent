<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\UserStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 后台用户管理（仅管理员）：列表、新增、禁用/启用、软删除。
 *
 * 写操作成功后落 audit_logs；不记录密码。
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): View
    {
        $query = User::query()->orderByDesc('id');

        $query->when($request->filled('keyword'), function ($q) use ($request) {
            $keyword = trim((string) $request->input('keyword'));
            $q->where(function ($qq) use ($keyword) {
                $qq->where('name', 'ilike', "%{$keyword}%")
                    ->orWhere('email', 'ilike', "%{$keyword}%");
            });
        });

        $query->when(
            in_array($request->input('role'), ['agent', 'admin'], true),
            fn ($q) => $q->where('role', $request->input('role')),
        );

        $query->when(
            in_array($request->input('status'), UserStatus::values(), true),
            fn ($q) => $q->where('status', $request->input('status')),
        );

        $users = $query->paginate(15)->withQueryString();

        return view('users.index', ['users' => $users]);
    }

    public function create(): View
    {
        return view('users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'max:128', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'role' => ['required', Rule::in(['agent', 'admin'])],
        ]);

        try {
            $user = $this->users->create($data);
        } catch (BusinessException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        AuditLogger::log('user.create', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status->value,
        ]);

        return redirect('/admin/users')->with('success', "用户 {$user->name} 已创建");
    }

    public function toggle(Request $request, int $id): RedirectResponse
    {
        $user = User::find($id);
        if (! $user) {
            return back()->with('error', '用户不存在');
        }

        try {
            $user = $this->users->toggleStatus($user, $request->user());
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
        }

        $enabled = $user->status === UserStatus::Active;

        AuditLogger::log($enabled ? 'user.enable' : 'user.disable', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status->value,
        ]);

        return back()->with('success', "用户 {$user->name} 已".($enabled ? '启用' : '禁用'));
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $user = User::find($id);
        if (! $user) {
            return back()->with('error', '用户不存在');
        }

        try {
            $this->users->delete($user, $request->user());
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLogger::log('user.delete', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status->value,
        ]);

        return back()->with('success', "用户 {$user->name} 已删除（软删除，历史数据保留）");
    }
}
