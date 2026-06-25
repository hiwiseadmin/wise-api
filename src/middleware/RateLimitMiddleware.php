<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\api\debug\DebugPanel;
use wise\api\rate\RateLimiter;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;

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
    public function __construct(
        protected App $app,
        protected RateLimiter $limiter,
        protected ResponseWrapper $wrapper,
        protected ?DebugPanel $debugPanel = null
    ) {
    }

    public function handle(Request $request, Closure $next, ...$params): Response
    {
        $config = $this->app->config('wise-api.rate_limit', []);
        if (!($config['enable'] ?? false)) {
            return $next($request);
        }

        $this->limiter->setConfig($config);

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
        $this->debugPanel?->record('rate_limit', [
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
                ErrorCode::RATE_LIMITED->value,
                '请求过于频繁，请稍后再试',
                ['retry_after_ms' => $result['retry_after_ms'], 'retry_after_s' => $retryAfter],
                (int) ($config['http_code'] ?? 429)
            );
            $response->header($this->buildRateHeaders($result, true, $retryAfter));
            $this->debugPanel?->attachToResponse($response);
            return $response;
        }

        /** @var Response $response */
        $response = $next($request);
        $response->header($this->buildRateHeaders($result, false));
        $this->debugPanel?->attachToResponse($response);
        return $response;
    }

    /**
     * 构建业界标准限流响应头
     *
     * @param array $result     RateLimiter::hit() 返回值
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
