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

    /** @var bool 是否自动注入 request_id + user_id */
    protected bool $withRequestId;

    public function __construct(?App $app = null)
    {
        $config = $app?->config->get('wise-api.response', []) ?? [];
        $this->successCode    = (int) ($config['success_code'] ?? 0);
        $this->successMsg     = (string) ($config['success_msg'] ?? 'success');
        $this->withTimestamp  = (bool) ($config['with_timestamp'] ?? true);
        $this->withRequestId  = (bool) ($config['with_request_id'] ?? true);
    }

    /**
     * 业务成功响应
     *
     * 签名与 wise\support\traits\JsonResponse 保持一致：msg 为第一参数。
     *
     * @param string        $msg  提示消息
     * @param mixed         $data 业务数据
     * @param ErrorCode|int $code 业务码（默认 ErrorCode::SUCCESS = 0）
     */
    public function success(string $msg = '', mixed $data = null, ErrorCode|int $code = ErrorCode::SUCCESS): Json
    {
        $codeValue = $code instanceof ErrorCode ? $code->value : $code;
        return $this->json([
            'code' => $codeValue ?: $this->successCode,
            'msg'  => $msg ?: $this->successMsg,
            'data' => $data,
        ]);
    }

    /**
     * 业务失败响应
     *
     * C4 fix: 统一接受 ErrorCode 枚举或 int，推荐直接传入枚举值以获得类型安全。
     *
     * @param string        $msg      错误描述
     * @param mixed         $data     附加数据
     * @param ErrorCode|int $code     错误码（推荐使用 ErrorCode 枚举）
     * @param int|null      $httpCode HTTP 状态码（默认根据 code 推断）
     */
    public function error(string $msg = 'error', mixed $data = [], ErrorCode|int $code = ErrorCode::INVALID_PARAM, ?int $httpCode = null): Json
    {
        $codeValue = $code instanceof ErrorCode ? $code->value : $code;
        return $this->json([
            'code' => $codeValue,
            'msg'  => $msg,
            'data' => $data,
        ], $httpCode ?? $this->httpCodeFor($code));
    }

    /**
     * 错误码 → HTTP 状态码映射
     *
     * C4 fix: 接受 ErrorCode 枚举或 int，内部转为 int 后匹配。
     *
     * @param ErrorCode|int $code
     */
    public function httpCodeFor(ErrorCode|int $code): int
    {
        $codeValue = $code instanceof ErrorCode ? $code->value : $code;

        return match (true) {
            $codeValue === ErrorCode::TOKEN_INVALID->value,
            $codeValue === ErrorCode::TOKEN_EXPIRED->value,
            $codeValue === ErrorCode::UNAUTHORIZED->value,
            $codeValue === ErrorCode::REFRESH_TOKEN_INVALID->value,
            $codeValue === ErrorCode::REFRESH_TOKEN_EXPIRED->value,
            $codeValue === ErrorCode::REFRESH_TOKEN_REVOKED->value => 401,
            $codeValue === ErrorCode::ENCRYPT_SESSION_EXPIRED->value,
            $codeValue === ErrorCode::ENCRYPT_SESSION_INVALID->value => 401,
            $codeValue === ErrorCode::FORBIDDEN->value,
            $codeValue === ErrorCode::IP_BLOCKED->value           => 403,
            $codeValue === ErrorCode::NOT_FOUND->value            => 404,
            $codeValue === ErrorCode::METHOD_NOT_ALLOWED->value   => 405,
            $codeValue === ErrorCode::RATE_LIMITED->value         => 429,
            $codeValue === ErrorCode::CIRCUIT_OPEN->value         => 503,
            $codeValue === ErrorCode::WEBHOOK_FAILED->value       => 502,
            $codeValue === ErrorCode::VERSION_NOT_SUPPORTED->value => 404,
            $codeValue === ErrorCode::VERSION_GONE->value          => 410,

            $codeValue === ErrorCode::INVALID_PARAM->value,
            $codeValue === ErrorCode::MISSING_PARAM->value        => 200,
            $codeValue >= 1000 && $codeValue < 2000               => 500,
            $codeValue >= 2301 && $codeValue <= 2302              => 500,
            $codeValue >= 2300 && $codeValue < 2400               => 200,
            $codeValue >= 4000 && $codeValue < 5000               => 200,
            $codeValue >= 5000                                    => 500,
            default                                               => 200,
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
        if ($this->withRequestId) {
            if (!isset($payload['request_id']) && function_exists('request_id')) {
                $payload['request_id'] = request_id();
            }
            if (!isset($payload['user_id']) && function_exists('auth_id')) {
                $userId = auth_id('api') ?? auth_id('admin');
                if ($userId !== null) {
                    $payload['user_id'] = $userId;
                }
            }
        }
        return json($payload, $httpCode);
    }
}
