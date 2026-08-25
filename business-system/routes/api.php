<?php

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // 公开：健康检查
    Route::get('health', [HealthController::class, 'index']);

    // agent 角色（查询 + 业务操作，写操作后续有审批兜底）
    Route::middleware('api.token:agent')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{orderNo}', [OrderController::class, 'show']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::put('orders/{orderNo}/address', [OrderController::class, 'updateAddress']);
        Route::post('orders/{orderNo}/cancel', [OrderController::class, 'cancel']);
        Route::get('orders/{orderNo}/tracking', [OrderController::class, 'tracking']);

        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{sku}', [ProductController::class, 'show']);

        Route::get('customers/{id}', [CustomerController::class, 'show']);

        Route::post('orders/{orderNo}/refunds', [RefundController::class, 'store']);
        Route::get('refunds', [RefundController::class, 'index']);

        Route::post('tasks', [TaskController::class, 'store']);
        Route::get('tasks/{taskNo}', [TaskController::class, 'show']);
    });

    // admin 角色（资金风险操作，仅人工审批）
    Route::middleware('api.token:admin')->group(function () {
        Route::post('refunds/{id}/approve', [RefundController::class, 'approve']);
        Route::post('refunds/{id}/reject', [RefundController::class, 'reject']);
    });
});
