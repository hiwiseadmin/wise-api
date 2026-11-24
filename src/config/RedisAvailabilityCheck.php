<?php
declare(strict_types=1);

namespace wise\api\config;

use think\App;
use think\facade\Config;
use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * wise-api Redis 可用性检查
 *
 * 检测 Redis 是否可用。以下功能依赖 Redis：
 *   - 限流（TokenBucket/SlidingWindow 依赖 Lua 脚本）
 *   - 签名 Nonce 防重放
 *   - Webhook 注册/投递
 *
 * 若 Redis 不可用，回退策略：
 *   - 限流：降级到文件缓存 RateLimiter，或 fail-open 放行
 *   - Nonce：校验直接返回失败（单请求级别，不影响框架运行）
 *   - Webhook：操作被 try-catch 包裹
 *
 * 此检查不会阻塞框架启动，仅记录日志和健康检查报告。
 */
class RedisAvailabilityCheck implements ConfigCheckInterface
{
    public function __construct(
        private readonly App $app,
    ) {
    }

    public function id(): string
    {
        return 'wise-api:redis';
    }

    public function package(): string
    {
        return 'wise-api';
    }

    public function level(): CheckLevel
    {
        return CheckLevel::FATAL;
    }

    public function check(): CheckResult
    {
        $rateEnabled    = Config::get('wise-api.rate_limit.enable', false);
        $signEnabled    = Config::get('wise-api.sign.enable', false);
        $signNonce      = Config::get('wise-api.sign.enable_nonce', true);
        $webhookEnabled = Config::get('wise-api.webhook.enable', false);

        $redisRequired = $rateEnabled || ($signEnabled && $signNonce) || $webhookEnabled;

        if (!$redisRequired) {
            return CheckResult::ok('无需 Redis');
        }

        try {
            $cache = $this->app->cache->store(
                Config::get('wise-api.rate_limit.store', 'redis')
            );
            $handler = $cache->handler();
            if (method_exists($handler, 'ping')) {
                $handler->ping();
            }
            return CheckResult::ok();
        } catch (\Throwable $e) {
            $affected = [];
            if ($rateEnabled) {
                $affected[] = '限流';
            }
            if ($signEnabled && $signNonce) {
                $affected[] = '签名 Nonce 防重放';
            }
            if ($webhookEnabled) {
                $affected[] = 'Webhook';
            }

            return CheckResult::fail(
                'Redis 不可用，以下功能将降级运行：' . implode('、', $affected)
                    . '（' . $e->getMessage() . '）',
                '请检查 Redis 服务是否启动，或确认 .env 中 REDIS_HOST 配置正确'
            );
        }
    }
}
