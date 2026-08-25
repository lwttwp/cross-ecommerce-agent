<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * 统一响应：{ code, message, data }
 */
class ApiResponse
{
    public static function ok(mixed $data = null, string $message = 'ok'): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public static function fail(int $code, string $message, int $httpStatus = 400, mixed $data = null): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $httpStatus);
    }
}
