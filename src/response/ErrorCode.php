<?php
declare(strict_types=1);

namespace wise\api\response;

use wise\support\exception\ErrorCodeInterface;

/**
 * 统一 API 错误码枚举
 *
 * 业务错误码定义规则：
 *  - 0      成功
 *  - 1xxx   通用错误（参数/服务器/未知）
 *  - 2xxx   鉴权与签名
 *  - 2300   传输加密
 *  - 3xxx   限流
 *  - 4xxx   业务校验
 *  - 5xxx   令牌与身份
 *  - 9xxx   第三方依赖
 *
 * 说明：本枚举是 wise-api 的「响应码」体系（0~9000），为生产环境既定契约，
 * 值保持不变。通过实现 ErrorCodeInterface 提供 defaultMessage()，
 * 使其同时可作为 WiseException::of() 的错误码来源。
 *
 * @package wise\api\response
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    case SUCCESS            = 0;
    case UNKNOWN_ERROR      = 1000;
    case INVALID_PARAM      = 1001;
    case MISSING_PARAM      = 1002;
    case METHOD_NOT_ALLOWED = 1003;
    case NOT_FOUND          = 1004;
    case SERVER_ERROR       = 1500;

    case UNAUTHORIZED       = 2000;
    case TOKEN_INVALID      = 2001;
    case TOKEN_EXPIRED      = 2002;
    case TOKEN_REFRESHED    = 2003;
    case SIGN_INVALID       = 2100;
    case SIGN_TIMESTAMP_BAD = 2101;
    case SIGN_NONCE_REUSED  = 2102;
    case FORBIDDEN          = 2200;
    case IP_BLOCKED         = 2201;

    case ENCRYPT_DISABLED        = 2300;
    case ENCRYPT_KEY_NOT_FOUND   = 2301;
    case ENCRYPT_KEY_INVALID     = 2302;
    case ENCRYPT_DECRYPT_FAILED  = 2303;
    case ENCRYPT_VERIFY_FAILED   = 2304;
    case ENCRYPT_FORMAT_INVALID  = 2305;
    case ENCRYPT_SESSION_EXPIRED = 2306;
    case ENCRYPT_SESSION_INVALID = 2307;
    case ENCRYPT_ALGO_UNSUPPORTED = 2308;

    case RATE_LIMITED       = 3000;

    case VALIDATE_FAILED    = 4000;
    case DATA_NOT_FOUND     = 4001;
    case DATA_CONFLICT      = 4002;
    case OPERATION_FAILED   = 4003;

    case VERSION_NOT_SUPPORTED = 4100;
    case VERSION_GONE          = 4101;

    case REFRESH_TOKEN_INVALID   = 5000;
    case REFRESH_TOKEN_EXPIRED   = 5001;
    case REFRESH_TOKEN_REVOKED   = 5002;

    case UPSTREAM_ERROR     = 9000;
    case CIRCUIT_OPEN       = 9001;
    case WEBHOOK_FAILED     = 9002;

    /**
     * 错误码对应的默认中文说明
     *
     * 供 WiseException::of() 在未显式提供 message 时自动使用。
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::SUCCESS            => '成功',
            self::UNKNOWN_ERROR      => '未知错误',
            self::INVALID_PARAM      => '参数无效',
            self::MISSING_PARAM      => '缺少必需参数',
            self::METHOD_NOT_ALLOWED => '请求方法不允许',
            self::NOT_FOUND          => '资源不存在',
            self::SERVER_ERROR       => '服务器内部错误',

            self::UNAUTHORIZED       => '未认证',
            self::TOKEN_INVALID      => '令牌无效',
            self::TOKEN_EXPIRED      => '令牌已过期',
            self::TOKEN_REFRESHED    => '令牌已刷新',
            self::SIGN_INVALID       => '签名验证失败',
            self::SIGN_TIMESTAMP_BAD => '签名时间戳异常',
            self::SIGN_NONCE_REUSED  => '签名随机数重复',
            self::FORBIDDEN          => '无权限访问',
            self::IP_BLOCKED         => 'IP 已被限制',

            self::ENCRYPT_DISABLED        => '接口要求加密但请求未加密',
            self::ENCRYPT_KEY_NOT_FOUND   => '加密密钥不存在',
            self::ENCRYPT_KEY_INVALID     => '加密密钥无效',
            self::ENCRYPT_DECRYPT_FAILED  => '解密失败',
            self::ENCRYPT_VERIFY_FAILED   => '数据完整性校验失败',
            self::ENCRYPT_FORMAT_INVALID  => '加密信封格式无效',
            self::ENCRYPT_SESSION_EXPIRED => '加密会话已过期',
            self::ENCRYPT_SESSION_INVALID => '加密会话无效',
            self::ENCRYPT_ALGO_UNSUPPORTED => '不支持的加密算法',

            self::RATE_LIMITED       => '请求过于频繁',

            self::VALIDATE_FAILED    => '数据校验失败',
            self::DATA_NOT_FOUND     => '数据不存在',
            self::DATA_CONFLICT      => '数据冲突',
            self::OPERATION_FAILED   => '操作失败',

            self::VERSION_NOT_SUPPORTED => 'API 版本不支持',
            self::VERSION_GONE          => 'API 版本已下线',

            self::REFRESH_TOKEN_INVALID => '刷新令牌无效',
            self::REFRESH_TOKEN_EXPIRED => '刷新令牌已过期',
            self::REFRESH_TOKEN_REVOKED => '刷新令牌已吊销',

            self::UPSTREAM_ERROR     => '上游服务错误',
            self::CIRCUIT_OPEN       => '服务熔断中',
            self::WEBHOOK_FAILED     => 'Webhook 投递失败',
        };
    }
}
