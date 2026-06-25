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
 * @package wise\api\rate
 */
class RateLimiter
{
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
        $path     = '/' . ltrim($request->pathinfo(), '/');
        $rule     = $rule ?? $this->resolveRule($path);
        $algorithm = $rule['algorithm'] ?? ($this->config['algorithm'] ?? 'token_bucket');
        $key      = $this->buildKey($request, $path);

        if ($algorithm === 'sliding_window') {
            $limit  = (int) ($rule['limit'] ?? 60);
            $window = (int) ($rule['window'] ?? 60);
            $info   = $this->window->attempt($key, $limit, $window);
            $resetSeconds = $window;
        } else {
            $capacity = (int) ($rule['capacity'] ?? 60);
            $rate     = (float) ($rule['rate'] ?? 1.0);
            $info     = $this->bucket->attempt($key, $capacity, $rate);
            $limit    = $capacity;
            // 令牌桶重置时间估算：填满空桶所需时间
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
     * 优先匹配 groups 中的前缀规则，否则使用 default。
     *
     * @param string $path 请求路径（避免重复调用 pathinfo()）
     */
    public function resolveRule(string $path): array
    {
        $groups = $this->config['groups'] ?? [];

        foreach ($groups as $prefix => $rule) {
            if ($prefix === $path || str_starts_with($path, $prefix . '/')) {
                return array_merge($this->config['default'] ?? [], $rule);
            }
        }

        return $this->config['default'] ?? [];
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
