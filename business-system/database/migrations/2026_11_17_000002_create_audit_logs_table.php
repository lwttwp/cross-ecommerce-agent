<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 写操作审计：谁、在哪个 trace、对什么对象、做了什么、带了什么参数。
 *
 * 与 trace_requests 的区别：trace_requests 是「技术链路」（含读请求），
 * audit_logs 只记「有副作用的操作」，是合规与追责依据。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id', 40)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 64)->nullable();
            $table->string('role', 16)->nullable();
            $table->string('action', 60);            // refund.apply / refund.approve / order.cancel / task.create ...
            $table->string('target_type', 40)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('trace_id');
            $table->index('action');
            $table->index('created_at');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
