<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 业务异常：code 为业务错误码（见架构文档 6.2），httpStatus 为 HTTP 状态码。
 */
class BusinessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $businessCode = 40901,
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}
