<?php

use App\Http\Controllers\Web\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 订单列表页（Web 视图示例）
Route::get('/orders', [OrderController::class, 'index']);
