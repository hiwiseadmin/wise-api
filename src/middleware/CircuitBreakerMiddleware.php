<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\support\circuit\CircuitBreaker;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\api\traits\HasDebugSupport;

/**
 * 断路器中间件 (F6)
 *
 * 将 CircuitBreaker 集成到中间件栈中，自动保护外部服务调用。
 *
 * 当断路器处于 OPEN 状态时，直接返回 503 拒绝请求，
 * 避免级联故障。HALF_OPEN 时允许少量探测请求通过。
 *
 * 中间件参数化：`:payment_service,5,30` 表示
 *   服务名=payment_service, 失败阈值=5, 冷却时间=30s
 *
 * 配置示例：
 *   ->middleware(\wise\api\middleware\CircuitBreakerMiddleware::class . ':payment,5,30')
 *
 * 用法（控制器）：
 *   class PaymentController extends ApiController
 *   {
 *       protected $middleware = [
 *           \wise\api\middleware\CircuitBreakerMiddleware::class . ':payment_service',
 *       ];
 *   }
 *
 * @package wise\api\middleware
 */
class CircuitBreakerMiddleware
{
    use HasDebugSupport;

    /** @var array<string, CircuitBreaker> 断路器实例缓存 */
    private array $breakers = [];

    /**
     * @param App             $app          ThinkPHP 应用实例
     * @param ResponseWrapper $wrapper      统一响应包装器
     * @param ?DebugPanel     $debugPanel   调试面板（nullable）
     */
    public function __construct(
        protected App $app,
        protected ResponseWrapper $wrapper,
        protected ?\wise\api\debug\DebugPanel $debugPanel = null
    ) {
    }

    /**
     * 处理请求
     *
     * @param Request $request  请求对象
     * @param Closure $next     下一个中间件
     * @param string  ...$params 服务名, 失败阈值, 冷却时间
     */
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        // 解析中间件参数
        $serviceName     = $params[0] ?? $this->resolveServiceName($request);
        $failureThreshold = (int) ($params[1] ?? 5);
        $timeout          = (int) ($params[2] ?? 30);

        // 获取或创建断路器实例（按服务名缓存）
        $breaker = $this->getBreaker($serviceName, $failureThreshold, $timeout);

        // 记录调试信息
        $this->debugRecord($this->debugPanel, 'circuit_breaker', [
            'service' => $serviceName,
            'state'   => $breaker->getState(),
            'failures'=> $breaker->getFailureCount(),
        ]);

        // 断路器打开 → 直接拒绝
        if (!$breaker->isAvailable()) {
            /** @var Response $response */
            $response = $this->wrapper->error(
                '服务暂时不可用，请稍后再试: ' . $serviceName,
                [
                    'service' => $serviceName,
                    'state'   => $breaker->getState(),
                ],
                ErrorCode::CIRCUIT_OPEN
            );
            $response->header(['Retry-After' => (string) $timeout]);
            $this->debugAttach($this->debugPanel, $response);
            return $response;
        }

        // 执行请求
        try {
            /** @var Response $response */
            $response = $next($request);

            // 根据响应状态码判断成功/失败
            $statusCode = $response->getCode();
            if ($statusCode >= 500) {
                // 上游服务 5xx → 上报失败
                $breaker->reportFailure();
                $this->debugRecord($this->debugPanel, 'circuit_breaker', [
                    'service'  => $serviceName,
                    'action'   => 'failure',
                    'status'   => $statusCode,
                    'failures' => $breaker->getFailureCount(),
                ]);
            } else {
                $breaker->reportSuccess();
            }
        } catch (\Throwable $e) {
            // 异常 → 上报失败
            $breaker->reportFailure();
            $this->debugRecord($this->debugPanel, 'circuit_breaker', [
                'service'  => $serviceName,
                'action'   => 'failure',
                'error'    => $e->getMessage(),
                'failures' => $breaker->getFailureCount(),
            ]);
            throw $e;
        }

        $this->debugAttach($this->debugPanel, $response);
        return $response;
    }

    /**
     * 获取或创建断路器实例
     */
    private function getBreaker(string $serviceName, int $failureThreshold, int $timeout): CircuitBreaker
    {
        $key = $serviceName . ':' . $failureThreshold . ':' . $timeout;

        if (!isset($this->breakers[$key])) {
            $cache = $this->app->cache;
            $appPrefix = (string) $this->app->config->get('wise-api.rate_limit.app_prefix', 'default');

            $this->breakers[$key] = new CircuitBreaker($serviceName, $cache, [
                'failure_threshold' => $failureThreshold,
                'timeout'           => $timeout,
                'key_prefix'        => 'wise_api:' . $appPrefix . ':cb',
            ]);
        }

        return $this->breakers[$key];
    }

    /**
     * 从请求路径解析服务名
     *
     * 例：/api/payment/charge → payment
     */
    private function resolveServiceName(Request $request): string
    {
        $path = trim($request->pathinfo(), '/');
        $parts = explode('/', $path);

        // 跳过 api / plugin 前缀
        foreach ($parts as $part) {
            if (in_array($part, ['api', 'plugin'], true)) {
                continue;
            }
            return $part;
        }

        return 'default';
    }
}
