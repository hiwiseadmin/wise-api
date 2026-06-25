<?php
declare(strict_types=1);

namespace wise\api\response;

use think\App;
use think\response\Json;

/**
 * 统一响应包装器
 *
 * 所有 API 返回值都应是 {code, msg, data, [timestamp]} 的 JSON。
 * 不依赖外部库，通过依赖注入 App 读取配置。
 *
 * @package wise\api\response
 */
class ResponseWrapper
{
    /** @var int 业务成功码 */
    protected int $successCode;

    /** @var string 业务成功消息 */
    protected string $successMsg;

    /** @var bool 是否输出 timestamp */
    protected bool $withTimestamp;

    public function __construct(?App $app = null)
    {
        $config = $app?->config('wise-api.response', []) ?? [];
        $this->successCode   = (int) ($config['success_code'] ?? 0);
        $this->successMsg    = (string) ($config['success_msg'] ?? 'success');
        $this->withTimestamp = (bool) ($config['with_timestamp'] ?? true);
    }

    /**
     * 业务成功响应
     *
     * @param mixed  $data    业务数据
     * @param string $msg     提示消息
     * @param int    $code    业务码（默认 0）
     */
    public function success(mixed $data = null, string $msg = '', int $code = 0): Json
    {
        return $this->json([
            'code' => $code ?: $this->successCode,
            'msg'  => $msg ?: $this->successMsg,
            'data' => $data,
        ]);
    }

    /**
     * 业务失败响应
     *
     * @param int         $code 错误码（推荐使用 ErrorCode 枚举）
     * @param string      $msg  错误描述
     * @param mixed       $data 附加数据
     * @param int|null    $httpCode HTTP 状态码（默认根据 error 类型推断）
     */
    public function error(int $code, string $msg = '', mixed $data = [], ?int $httpCode = null): Json
    {
        return $this->json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ], $httpCode ?? $this->httpCodeFor($code));
    }

    /**
     * 错误码 → HTTP 状态码映射
     */
    public function httpCodeFor(int $code): int
    {
        return match (true) {
            $code === ErrorCode::TOKEN_INVALID->value,
            $code === ErrorCode::TOKEN_EXPIRED->value,
            $code === ErrorCode::UNAUTHORIZED->value,
            $code === ErrorCode::REFRESH_TOKEN_INVALID->value,
            $code === ErrorCode::REFRESH_TOKEN_EXPIRED->value,
            $code === ErrorCode::REFRESH_TOKEN_REVOKED->value => 401,
            $code === ErrorCode::FORBIDDEN->value,
            $code === ErrorCode::IP_BLOCKED->value           => 403,
            $code === ErrorCode::NOT_FOUND->value            => 404,
            $code === ErrorCode::METHOD_NOT_ALLOWED->value   => 405,
            $code === ErrorCode::RATE_LIMITED->value         => 429,
            $code === ErrorCode::CIRCUIT_OPEN->value         => 503,
            $code === ErrorCode::WEBHOOK_FAILED->value       => 502,
            $code >= 4000 && $code < 5000                    => 422,
            $code >= 5000                                    => 500,
            default                                          => 400,
        };
    }

    /**
     * 直接构造 JSON 响应
     *
     * @param array<string,mixed> $payload
     */
    public function json(array $payload, int $httpCode = 200): Json
    {
        if ($this->withTimestamp && !isset($payload['timestamp'])) {
            $payload['timestamp'] = time();
        }
        return json($payload, $httpCode);
    }
}
