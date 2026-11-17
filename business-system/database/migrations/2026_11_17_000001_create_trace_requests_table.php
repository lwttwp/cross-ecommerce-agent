<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 全链路 span 表：每个服务的一段处理一行，按 trace_id 聚合即是一条调用链。
 *
 * channel: http（业务 API / 后台页面）| agent（Agent 单轮对话）| queue（队列任务）| event（领域事件）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_requests', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id', 40);
            $table->string('span', 60);              // ws.message / agent.round / http.request / queue.job / event.publish
            $table->string('channel', 16);           // http | agent | queue | event
            $table->string('method', 8)->nullable();
            $table->text('path')->nullable();
            $table->string('route', 120)->nullable();
            $table->integer('status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 64)->nullable();
            $table->string('role', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('session_id', 40)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('trace_id');
            $table->index('created_at');
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_requests');
    }
};
