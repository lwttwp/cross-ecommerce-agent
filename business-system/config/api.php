<?php

return [
    /*
    | 业务 API 的 Token 配置（双层鉴权）
    | agent: Agent/MCP 调用业务接口
    | admin: 人工审批等高风险操作
    */
    'tokens' => [
        'agent' => env('API_TOKEN_AGENT', ''),
        'admin' => env('API_TOKEN_ADMIN', ''),
    ],
];
