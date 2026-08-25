<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\RateController;
use App\Http\Controllers\Web\RefundController;
use App\Http\Controllers\Web\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// 登录
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// 后台（需 session 登录）
Route::middleware('auth')->group(function () {
    Route::get('/admin', [DashboardController::class, 'index']);

    // 订单
    Route::get('/admin/orders', [OrderController::class, 'index']);
    Route::get('/admin/orders/{orderNo}', [OrderController::class, 'show']);
    Route::get('/admin/orders/{orderNo}/refund', [OrderController::class, 'refundForm']);
    Route::post('/admin/orders/{orderNo}/refund', [OrderController::class, 'refund']);

    // 客户
    Route::get('/admin/customers', [CustomerController::class, 'index']);
    Route::get('/admin/customers/{id}', [CustomerController::class, 'show']);

    // 商品
    Route::get('/admin/products', [ProductController::class, 'index']);
    Route::get('/admin/products/{sku}', [ProductController::class, 'show']);

    // 退款审批
    Route::get('/admin/refunds', [RefundController::class, 'index']);
    Route::get('/admin/refunds/{id}', [RefundController::class, 'show']);
    Route::post('/admin/refunds/{id}/approve', [RefundController::class, 'approve']);
    Route::post('/admin/refunds/{id}/reject', [RefundController::class, 'reject']);

    // 异步任务
    Route::get('/admin/tasks', [TaskController::class, 'index']);
    Route::get('/admin/tasks/{taskNo}', [TaskController::class, 'show']);
    Route::get('/admin/tasks/{taskNo}/download', [TaskController::class, 'download']);

    // 汇率
    Route::get('/admin/rates', [RateController::class, 'index']);
});

// 旧路径重定向到后台
Route::get('/orders', fn () => redirect('/admin/orders'));
Route::get('/refunds', fn () => redirect('/admin/refunds'));
