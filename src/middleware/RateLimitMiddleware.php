<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\api\debug\DebugPanel;
use wise\api\rate\RateLimitCoordinator;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\api\traits\HasDebugSupport;

/**
 * 限流中间件
 *
 * 支持中间件参数化（与 ThinkPHP 一致）：`:20,60` 表示「20 个/60 秒」
 * 当 algorithm=token_bucket 时，参数解释为「容量, 填充速率（个/秒）」
 * 当 algorithm=sliding_window 时，参数解释为「最大次数, 窗口秒数」
 *
 * 示例：
 *   ->middleware(\wise\api\middleware\RateLimitMiddleware::class . ':60,1')   // 令牌桶
 *   ->middleware(\wise\api\middleware\RateLimitMiddleware::class . ':20,60')  // 滑动窗口
 *
 * 也可通过 config('wise-api.rate_limit.groups') 按 API 分组预设规则。
 *
 * 响应头（业界标准限流头）：
 *   X-RateLimit-Limit      配额上限
 *   X-RateLimit-Remaining  当前剩余
 *   X-RateLimit-Reset      配额重置时间戳（秒）
 *   Retry-After            超限时建议重试秒数（仅 429）
 *
 * @package wise\api\middleware
 */
class RateLimitMiddleware
{
    use HasDebugSupport;
    public function __construct(
        protected App $app,
        protected RateLimitCoordinator $limiter,
        protected ResponseWrapper $wrapper,
        protected ?DebugPanel $debugPanel = null
    ) {
    }

    public function handle(Request $request, Closure $next, ...$params): Response
    {
        $config = $this->app->config->get('wise-api.rate_limit', []);
        if (!($config['enable'] ?? false)) {
            return $next($request);
        }

        $this->limiter->setConfig($config);

        // F3: 从请求中读取用户等级，注入到限流器。
        // 限流器是容器共享实例：常驻运行时（Swoole/队列）必须每次无条件重置，
        // 否则上一个请求的 tier 会被后续匿名请求继承（越权获得高等级配额）。
        $this->limiter->setUserTier($request->wise_user['tier'] ?? null);

        // 中间件参数覆盖：仅当显式传参时覆盖配置
        $rule = null;
        if (!empty($params)) {
            $algorithm = $config['algorithm'] ?? 'token_bucket';
            $rule = $algorithm === 'sliding_window'
                ? ['algorithm' => 'sliding_window', 'limit' => (int) ($params[0] ?? 60), 'window' => (int) ($params[1] ?? 60)]
                : ['algorithm' => 'token_bucket',   'capacity' => (int) ($params[0] ?? 60), 'rate' => (float) ($params[1] ?? 1.0)];
        }

        $result = $this->limiter->hit($request, $rule);

        // 记录调试信息
        $this->debugRecord($this->debugPanel, 'rate_limit', [
            'key'       => $result['key'],
            'algorithm' => $result['algorithm'],
            'allowed'   => $result['allowed'],
            'info'      => $result['limit_info'],
        ]);

        // 限流命中 → 返回 429 + 完整限流响应头
        if (!$result['allowed']) {
            /** @var Response $response */
            $retryAfter = (int) ceil(($result['retry_after_ms'] ?: 1000) / 1000);
            $response = $this->wrapper->error(
                '请求过于频繁，请稍后再试',
                ['retry_after_ms' => $result['retry_after_ms'], 'retry_after_s' => $retryAfter],
                ErrorCode::RATE_LIMITED,
                (int) ($config['http_code'] ?? 429)
            );
            foreach ($this->buildRateHeaders($result, true, $retryAfter) as $name => $value) {
                $response->header([$name => $value]);
            }
            $this->debugAttach($this->debugPanel, $response);
            return $response;
        }

        /** @var Response $response */
        $response = $next($request);
        foreach ($this->buildRateHeaders($result, false) as $name => $value) {
            $response->header([$name => $value]);
        }
        $this->debugAttach($this->debugPanel, $response);
        return $response;
    }

    /**
     * 构建业界标准限流响应头
     *
     * @param array $result     RateLimitCoordinator::hit() 返回值
     * @param bool  $limited    是否已触发限流
     * @param int   $retryAfter 超限时 Retry-After 秒数（仅 limited=true 时有效）
     * @return array<string,string>
     */
    private function buildRateHeaders(array $result, bool $limited, int $retryAfter = 0): array
    {
        $remaining = (string) ($result['limit_info']['remaining'] ?? '0');
        $headers = [
            'X-RateLimit-Algorithm'  => $result['algorithm'],
            'X-RateLimit-Limit'      => (string) $result['limit'],
            'X-RateLimit-Remaining'  => $remaining,
            'X-RateLimit-Reset'      => (string) $result['reset_at'],
        ];

        if ($limited && $retryAfter > 0) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return $headers;
    }
}
