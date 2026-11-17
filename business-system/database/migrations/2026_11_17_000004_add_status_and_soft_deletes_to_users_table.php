<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 用户管理：启用/禁用状态 + 软删除。
 *
 * 删除用软删除：退款审批人、任务创建人、审计记录、聊天会话都保留，
 * 用户从列表与登录态中消失，但历史归属不丢。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('role');
            $table->softDeletes();
            $table->index('status');
        });

        // 用户列表按姓名/邮箱模糊搜索；与其他列表页一样走 pg_trgm GIN
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS users_name_trgm ON users USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_email_trgm ON users USING gin (email gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_name_trgm');
        DB::statement('DROP INDEX IF EXISTS users_email_trgm');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropSoftDeletes();
            $table->dropColumn('status');
        });
    }
};
