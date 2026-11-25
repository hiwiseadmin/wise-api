<?php

declare(strict_types=1);

namespace wise\api\exception;

use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * API 日志异常
 *
 * 日志基础设施异常，错误码复用 wise-support 的 GeneralErrorCode（100~999）：
 *   - driverError   → IO_ERROR (303)
 *   - configError   → CONFIG_ERROR (300)
 *   - tableNotFound → DB_ERROR (301)
 */
class LogException extends WiseException
{
    /**
     * @param string          $message  异常消息
     * @param int             $code     业务错误码（传 0 时回退到 IO_ERROR）
     * @param \Throwable|null $previous 前置异常
     * @param array           $context  上下文数据
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = [],
    ) {
        $code = $code ?: GeneralErrorCode::IO_ERROR->value;
        parent::__construct($code, $message, 500, $previous, $context);
    }

    /**
     * 创建驱动异常
     */
    public static function driverError(string $driver, string $message, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('[ApiLogger] 驱动 %s 错误: %s', $driver, $message),
            $code ?: GeneralErrorCode::IO_ERROR->value,
            $previous,
            ['driver' => $driver],
        );
    }

    /**
     * 创建配置异常
     */
    public static function configError(string $message, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('[ApiLogger] 配置错误: %s', $message),
            $code ?: GeneralErrorCode::CONFIG_ERROR->value,
            $previous,
        );
    }

    /**
     * 创建表不存在异常
     */
    public static function tableNotFound(string $table): self
    {
        return new self(
            sprintf('[ApiLogger] 数据表 %s 不存在，请先执行 php think api-log:install 建表', $table),
            GeneralErrorCode::DB_ERROR->value,
            null,
            ['table' => $table],
        );
    }
}
