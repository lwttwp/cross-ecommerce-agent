<?php

declare(strict_types=1);

namespace Mcp;

/**
 * 极简 .env 解析(零依赖)。
 * 支持 KEY=VALUE、空行、# 注释、双引号包裹的值。
 */
final class Env
{
    /** @return array<string, string> */
    public static function load(string $path): array
    {
        $vars = [];
        if (!is_file($path)) {
            return $vars;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $vars[$key] = $value;
        }
        return $vars;
    }

    /**
     * 合并环境变量与 .env:真实环境变量优先,.env 兜底。
     *
     * @param array<string, string> $env
     */
    public static function merge(array $env): array
    {
        $result = $env;
        foreach (getenv() as $key => $value) {
            if (is_string($value) && $value !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
