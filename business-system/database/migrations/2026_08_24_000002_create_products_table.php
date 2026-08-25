<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->integer('stock')->default(0);
            $table->decimal('weight_kg', 8, 2)->default(0);
            $table->string('status')->default('on'); // on | off
            $table->timestamps();
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
