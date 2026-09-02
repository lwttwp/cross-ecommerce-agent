<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 右模糊搜索专用索引（text_pattern_ops：支持 LIKE '前缀%' 走 B-tree range scan）。
 *
 * 背景：库 collation 非 C，普通 B-tree 不服务 LIKE/ILIKE 前缀匹配；
 * %kw% 只能走 pg_trgm GIN。订单号/退款单号搜索是前缀场景（输 CE2024... / RF2026...），
 * 右模糊 + text_pattern_ops 索引是最快路径（Index Scan，非 Bitmap+Heap）。
 * 注意：text_pattern_ops 只支持 LIKE（大小写敏感），代码里对单号做 strtoupper 后匹配。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_order_no_pattern ON orders (order_no text_pattern_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_refunds_refund_no_pattern ON refunds (refund_no text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_orders_order_no_pattern');
        DB::statement('DROP INDEX IF EXISTS idx_refunds_refund_no_pattern');
    }
};
