<?php

use App\Enums\UserStatus;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\RagController;
use App\Http\Controllers\Web\RateController;
use App\Http\Controllers\Web\RefundController;
use App\Http\Controllers\Web\TaskController;
use App\Http\Controllers\Web\TraceController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// 登录
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

/**
 * 当前登录用户（chat 服务用它做 SSO 校验）。
 * 不跳转、不返回 HTML：未登录就是 401 JSON，便于 /chat 反代侧判定。
 */
Route::get('/session/me', function (Request $request) {
    $user = $request->user();
    if ($user === null || $user->status !== UserStatus::Active) {
        return response()->json(['code' => 401, 'message' => '未登录或账号已禁用', 'data' => null], 401);
    }

    return response()->json([
        'code' => 0,
        'message' => 'ok',
        'data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ],
    ]);
});

// 后台（需 session 登录 + 账号启用）
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/admin', [DashboardController::class, 'index']);

    // 订单
    Route::get('/admin/orders', [OrderController::class, 'index']);
    Route::get('/admin/orders/create', [OrderController::class, 'createForm']);
    Route::post('/admin/orders', [OrderController::class, 'store']);
    Route::get('/admin/orders/{orderNo}', [OrderController::class, 'show']);
    Route::get('/admin/orders/{orderNo}/refund', [OrderController::class, 'refundForm']);
    Route::post('/admin/orders/{orderNo}/refund', [OrderController::class, 'refund']);
    Route::get('/admin/orders/{orderNo}/ship', [OrderController::class, 'shipForm']);
    Route::post('/admin/orders/{orderNo}/ship', [OrderController::class, 'ship']);
    Route::post('/admin/orders/{orderNo}/pay', [OrderController::class, 'pay']);
    Route::post('/admin/orders/{orderNo}/complete', [OrderController::class, 'complete']);

    // 客户
    Route::get('/admin/customers', [CustomerController::class, 'index']);
    Route::get('/admin/customers/{id}', [CustomerController::class, 'show']);

    // 商品
    Route::get('/admin/products', [ProductController::class, 'index']);
    Route::get('/admin/products/{sku}', [ProductController::class, 'show']);

    // 退款审批（资金操作，仅管理员）
    Route::get('/admin/refunds', [RefundController::class, 'index']);
    Route::get('/admin/refunds/{id}', [RefundController::class, 'show']);
    Route::post('/admin/refunds/{id}/approve', [RefundController::class, 'approve'])->middleware('role:admin');
    Route::post('/admin/refunds/{id}/reject', [RefundController::class, 'reject'])->middleware('role:admin');

    // 用户管理（仅管理员）
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/users', [UserController::class, 'index']);
        Route::get('/admin/users/create', [UserController::class, 'create']);
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::post('/admin/users/{id}/toggle', [UserController::class, 'toggle']);
        Route::delete('/admin/users/{id}', [UserController::class, 'destroy']);
    });

    // RAG 管理（仅管理员）
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/rag', [RagController::class, 'index']);
        Route::post('/admin/rag/upload', [RagController::class, 'upload']);
        Route::post('/admin/rag/rebuild', [RagController::class, 'rebuild']);
        Route::post('/admin/rag/clear', [RagController::class, 'clear']);
    });

    // 全链路追踪与审计（仅管理员）
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/traces', [TraceController::class, 'index']);
        Route::get('/admin/traces/{traceId}', [TraceController::class, 'show']);
    });

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
