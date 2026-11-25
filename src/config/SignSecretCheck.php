<?php
declare(strict_types=1);

namespace wise\api\config;

use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * wise-api 签名密钥检测
 */
class SignSecretCheck implements ConfigCheckInterface
{
    public function __construct(
        private readonly \think\Config $config,
    ) {
    }

    public function id(): string
    {
        return 'wise-api:sign-secret';
    }

    public function package(): string
    {
        return 'wise-api';
    }

    public function level(): CheckLevel
    {
        return CheckLevel::ERROR;
    }

    public function check(): CheckResult
    {
        // 签名功能未启用时无需检查密钥
        if (!$this->config->get('wise-api.sign.enable', false)) {
            return CheckResult::ok();
        }

        $secret = (string) $this->config->get('wise-api.sign.secret', '');
        $default = 'please-change-me-in-production-sign-secret!';

        if ($secret === $default) {
            return CheckResult::fail(
                'JWT_SIGN_SECRET 仍为默认值，API 签名验证处于不安全状态',
                '请在 .env 中设置 JWT_SIGN_SECRET'
            );
        }

        return CheckResult::ok();
    }
}
