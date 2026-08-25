<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_no')->unique();
            $table->string('type'); // report:monthly_sales | report:refund_rate | export:orders
            $table->jsonb('params')->nullable();
            $table->string('status'); // pending | running | success | failed
            $table->jsonb('result_summary')->nullable();
            $table->string('result_path')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
