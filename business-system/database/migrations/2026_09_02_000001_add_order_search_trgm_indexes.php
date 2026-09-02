<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 订单列表模糊搜索提速：pg_trgm GIN 索引。
 *
 * 背景：后台订单列表 keyword 搜索是 order_no ILIKE '%kw%'（前后通配），
 * 普通 B-tree 索引无效 → 百万级订单全表扫描。
 * pg_trgm 三元组索引支持 %kw% 包含匹配（需 3+ 字符关键词）。
 * 同时给 customers.name/email 建（orWhereHas 客户名/邮箱模糊搜索同样受益）。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_order_no_trgm ON orders USING gin (order_no gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_customers_name_trgm ON customers USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_customers_email_trgm ON customers USING gin (email gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_orders_order_no_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_customers_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_customers_email_trgm');
    }
};
