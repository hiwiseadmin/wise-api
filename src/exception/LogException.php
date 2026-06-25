<?php

declare(strict_types=1);

namespace wise\api\exception;

/**
 * API日志异常
 */
class LogException extends \RuntimeException
{
    /**
     * 创建驱动异常
     */
    public static function driverError(string $driver, string $message, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('[ApiLogger] 驱动 %s 错误: %s', $driver, $message),
            $code,
            $previous
        );
    }

    /**
     * 创建配置异常
     */
    public static function configError(string $message, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('[ApiLogger] 配置错误: %s', $message),
            $code,
            $previous
        );
    }

    /**
     * 创建表不存在异常
     */
    public static function tableNotFound(string $table): self
    {
        return new self(
            sprintf('[ApiLogger] 数据表 %s 不存在，请先执行 php think api-log:install 建表', $table)
        );
    }
}
