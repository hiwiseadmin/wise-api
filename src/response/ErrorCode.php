<?php
declare(strict_types=1);

namespace wise\api\response;

/**
 * 统一 API 错误码枚举
 *
 * 业务错误码定义规则：
 *  - 0      成功
 *  - 1xxx   通用错误（参数/服务器/未知）
 *  - 2xxx   鉴权与签名
 *  - 3xxx   限流
 *  - 4xxx   业务校验
 *  - 5xxx   令牌与身份
 *  - 9xxx   第三方依赖
 *
 * @package wise\api\response
 */
enum ErrorCode: int
{
    // -------------------- 通用 --------------------
    case SUCCESS            = 0;
    case UNKNOWN_ERROR      = 1000;
    case INVALID_PARAM      = 1001;
    case MISSING_PARAM      = 1002;
    case METHOD_NOT_ALLOWED = 1003;
    case NOT_FOUND          = 1004;
    case SERVER_ERROR       = 1500;

    // -------------------- 鉴权 / 签名 --------------------
    case UNAUTHORIZED       = 2000;
    case TOKEN_INVALID      = 2001;
    case TOKEN_EXPIRED      = 2002;
    case TOKEN_REFRESHED    = 2003; // 触发自动刷新，HTTP 仍 200
    case SIGN_INVALID       = 2100;
    case SIGN_TIMESTAMP_BAD = 2101;
    case SIGN_NONCE_REUSED  = 2102;
    case FORBIDDEN          = 2200;
    case IP_BLOCKED         = 2201; // IP 黑名单/不在白名单

    // -------------------- 限流 --------------------
    case RATE_LIMITED       = 3000;

    // -------------------- 业务校验 --------------------
    case VALIDATE_FAILED    = 4000;
    case DATA_NOT_FOUND     = 4001;
    case DATA_CONFLICT      = 4002;
    case OPERATION_FAILED   = 4003;

    // -------------------- 令牌与身份 --------------------
    case REFRESH_TOKEN_INVALID   = 5000;
    case REFRESH_TOKEN_EXPIRED   = 5001;
    case REFRESH_TOKEN_REVOKED   = 5002;

    // -------------------- 第三方 --------------------
    case UPSTREAM_ERROR     = 9000;
    case CIRCUIT_OPEN       = 9001; // 断路器打开，拒绝请求
    case WEBHOOK_FAILED     = 9002; // Webhook 投递失败
}
