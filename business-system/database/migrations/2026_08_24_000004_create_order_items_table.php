<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('sku');
            $table->string('name'); // 商品名快照，防止商品改名影响历史订单
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
