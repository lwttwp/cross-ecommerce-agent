<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款单记录申请人（多用户后需要回答「这单是谁提的」）。
 * 审批人仍用 approver_id，但改为写入真实操作人，不再取「第一个 admin」。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->after('order_status_before');
            $table->string('requested_by_name', 64)->nullable()->after('requested_by_user_id');

            $table->index('requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['requested_by_user_id']);
            $table->dropColumn(['requested_by_user_id', 'requested_by_name']);
        });
    }
};
