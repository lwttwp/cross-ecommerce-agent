<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserStatus;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 后台用户管理：新增、禁用/启用、软删除。
 *
 * 安全护栏：
 * - 不能禁用/启用/删除当前登录账号（避免把自己锁在外面）；
 * - 不能禁用/删除最后一个启用中的管理员（避免后台失去审批人）。
 */
class UserService
{
    public function create(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],   // User 模型 hashed cast 负责加密
            'role' => $data['role'],
            'status' => UserStatus::Active,
        ]);
    }

    public function toggleStatus(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor) {
            if ($user->id === $actor->id) {
                throw new BusinessException('不能禁用或启用当前登录账号', 40901);
            }

            if ($user->status === UserStatus::Active) {
                $this->ensureNotLastActiveAdmin($user);
                $user->update(['status' => UserStatus::Disabled]);
            } else {
                $user->update(['status' => UserStatus::Active]);
            }

            return $user->refresh();
        });
    }

    public function delete(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor) {
            if ($user->id === $actor->id) {
                throw new BusinessException('不能删除当前登录账号', 40901);
            }

            if ($user->status === UserStatus::Active) {
                $this->ensureNotLastActiveAdmin($user);
            }

            $user->delete();   // SoftDeletes：历史归属保留
        });
    }

    private function ensureNotLastActiveAdmin(User $user): void
    {
        if ($user->role !== 'admin') {
            return;
        }

        $others = User::query()
            ->where('role', 'admin')
            ->where('status', UserStatus::Active->value)
            ->where('id', '!=', $user->id)
            ->count();

        if ($others === 0) {
            throw new BusinessException('不能禁用或删除最后一个启用中的管理员', 40901);
        }
    }
}
