<?php
declare(strict_types=1);

namespace wise\api\rate;

use think\Request;

/**
 * 限流协调者（统一入口）
 *
 * 根据配置中的 algorithm 自动选择令牌桶或滑动窗口。
 * 限流键（key）由 key_by 决定：ip | user_id | api_path | combine
 *
 * 注意：与 wise\support\rate\RateLimiter（通用固定窗口计数器）不同，
 * 本类负责 API 层的路由规则匹配、用户等级差异化、多算法协调等编排逻辑。
 *
 * @package wise\api\rate
 */
class RateLimitCoordinator
{
    /** @var string|null 当前请求的用户等级 */
    private ?string $userTier = null;

    public function __construct(
        protected TokenBucket $bucket,
        protected SlidingWindow $window,
        protected array $config = []
    ) {
    }

    /**
     * 设置全局配置（在中间件初始化时调用）
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * 命中限流检查
     *
     * @return array{
     *   allowed: bool,
     *   algorithm: string,
     *   key: string,
     *   limit: int,
     *   reset_at: int,
     *   retry_after_ms: int,
     *   limit_info: array<string,mixed>
     * }
     */
    public function hit(Request $request, ?array $rule = null): array
    {
        $path      = '/' . ltrim($request->pathinfo(), '/');
        $rule      = $rule ?? $this->resolveRule($path);
        $algorithm = $rule['algorithm'] ?? ($this->config['algorithm'] ?? 'token_bucket');
        $key       = $this->buildKey($request, $path);
        $limit     = 60;
        $resetSeconds = 60;

        if ($algorithm === 'sliding_window') {
            $limit  = (int) ($rule['limit'] ?? 60);
            $window = (int) ($rule['window'] ?? 60);
            $info   = $this->window->attempt($key, ['limit' => $limit, 'window' => $window]);
            $resetSeconds = $window;
        } else {
            $capacity = (int) ($rule['capacity'] ?? 60);
            $rate     = (float) ($rule['rate'] ?? 1.0);
            $info     = $this->bucket->attempt($key, ['capacity' => $capacity, 'rate' => $rate]);
            $limit    = $capacity;
            $resetSeconds = (int) ceil($capacity / max(0.001, $rate));
        }

        return [
            'allowed'        => $info['allowed'],
            'algorithm'      => $algorithm,
            'key'            => $key,
            'limit'          => $limit,
            'reset_at'       => time() + $resetSeconds,
            'retry_after_ms' => $info['retry_after_ms'] ?? ($info['allowed'] ? 0 : 1000),
            'limit_info'     => $info,
        ];
    }

    /**
     * 解析当前请求命中的限流规则
     *
     * 优先级：1. groups 前缀匹配 → 2. tiers 用户等级覆盖 → 3. default
     *
     * F3: 支持按用户等级（tier）的差异化限流。
     * 用户等级从 $request->wise_user['tier'] 读取，
     * 未设置时回退到 'default' tier。
     *
     * @param string $path 请求路径
     */
    public function resolveRule(string $path): array
    {
        $groups = $this->config['groups'] ?? [];

        foreach ($groups as $prefix => $rule) {
            if ($prefix === $path || str_starts_with($path, $prefix . '/')) {
                return $this->mergeTierRule(
                    $this->config['default'] ?? [],
                    $rule
                );
            }
        }

        return $this->mergeTierRule(
            $this->config['default'] ?? [],
            []
        );
    }

    /**
     * F3: 根据用户等级合并限流规则
     *
     * 若 rule 中包含 tiers 配置，按用户等级覆盖 capacity/rate/limit/window。
     * tier 优先级：rule.tiers.{tier} > rule (顶层) > default
     *
     * @param array $defaultRule 全局默认规则
     * @param array $groupRule   分组规则（可含 tiers）
     */
    private function mergeTierRule(array $defaultRule, array $groupRule): array
    {
        $baseRule = array_merge($defaultRule, $groupRule);

        // 获取当前用户等级
        $tier = $this->getUserTier();
        if ($tier === null) {
            return $baseRule;
        }

        // 检查分组规则中是否有对应等级的覆盖
        $tierOverrides = $groupRule['tiers'][$tier] ?? null;
        if ($tierOverrides === null) {
            return $baseRule;
        }

        return array_merge($baseRule, $tierOverrides);
    }

    /**
     * F3: 获取当前请求的用户等级
     *
     * 优先级：显式 setUserTier() > request 属性 > JWT claims
     * 返回 null 表示未设置等级（使用默认规则）。
     *
     * @return string|null tier 标识（如 'free'/'premium'/'enterprise'），null 表示未定义
     */
    public function getUserTier(): ?string
    {
        // 若中间件已显式设置，直接返回
        if ($this->userTier !== null) {
            return $this->userTier;
        }

        // 注意：RateLimitCoordinator 不持有 Request 引用，无法自动读取
        // 由 RateLimitMiddleware::handle() 中调用 limiter->setUserTier() 注入
        return null;
    }

    /**
     * F3: 设置用户等级（在中间件中调用）
     *
     * @param string|null $tier 用户等级
     */
    public function setUserTier(?string $tier): void
    {
        $this->userTier = $tier;
    }

    /**
     * 构造限流键（含多应用隔离前缀）
     *
     * @param Request $request
     * @param string  $path    请求路径（避免重复调用 pathinfo()）
     */
    public function buildKey(Request $request, string $path): string
    {
        $by  = $this->config['key_by'] ?? 'combine';
        $ip  = $request->ip();
        $uid = (string) ($request->wise_user['id'] ?? $request->wise_user['user_id'] ?? 'guest');
        $api = $path;

        // 多应用隔离前缀（避免不同应用间的限流键冲突）
        $appPrefix = $this->config['app_prefix'] ?? '';
        $ns = $appPrefix !== '' ? "{$appPrefix}:" : '';

        return match ($by) {
            'ip'        => "{$ns}ip:{$ip}",
            'user_id'   => "{$ns}uid:{$uid}",
            'api_path'  => "{$ns}api:{$api}",
            'combine'   => "{$ns}{$ip}|{$uid}|{$api}",
            default     => "{$ns}{$ip}|{$api}",
        };
    }
}
