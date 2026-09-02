<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * refunds.refund_no 模糊搜索索引（Api\RefundController 列表筛选改 ILIKE %kw% 后需要）。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_refunds_refund_no_trgm ON refunds USING gin (refund_no gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_refunds_refund_no_trgm');
    }
};
