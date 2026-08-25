<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::ok([
            'service' => 'cross-ecommerce-business-system',
            'time' => now()->toIso8601String(),
        ]);
    }
}
