<?php

use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\RefundController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 订单列表页（Web 视图示例）
Route::get('/orders', [OrderController::class, 'index']);

// 退款审批页（M2：human-in-the-loop 人工审批）
Route::get('/refunds', [RefundController::class, 'index']);
Route::post('/refunds/{id}/approve', [RefundController::class, 'approve']);
Route::post('/refunds/{id}/reject', [RefundController::class, 'reject']);
