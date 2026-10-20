<?php

declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\api\dispatcher\LogDispatcher;
use wise\api\dto\ApiLog;

/**
 * API日志中间件
 *
 * 采集请求和响应数据，构建 ApiLog DTO，调用 LogDispatcher::dispatch()
 * 使用 hrtime(true) 精确计时，支持跳过规则检查
 */
class ApiLogMiddleware
{
    /** @var App ThinkPHP 应用实例 */
    private App $app;

    /** @var LogDispatcher 日志调度器 */
    private LogDispatcher $dispatcher;

    /**
     * @param App $app ThinkPHP 应用实例
     * @param LogDispatcher $dispatcher 日志调度器
     */
    public function __construct(App $app, LogDispatcher $dispatcher)
    {
        $this->app = $app;
        $this->dispatcher = $dispatcher;
    }

    /**
     * 处理请求
     *
     * @param Request $request 请求对象
     * @param Closure(Request): Response $next 下一个中间件
     * @return Response 响应对象
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 检查是否跳过此请求
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        // 全局开关检查
        if (!$this->app->config->get('wise-api.logger.enabled', true)) {
            return $next($request);
        }

        // 使用 hrtime(true) 精确计时（纳秒）
        $startTime = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        // 计算耗时（毫秒）
        $durationMs = (int)((hrtime(true) - $startTime) / 1000000);

        // 慢请求检测
        $this->checkSlowRequest($request, $durationMs);

        try {
            // 构建 ApiLog DTO
            $apiLog = $this->buildApiLog($request, $response, $durationMs);

            // 分发到日志驱动
            $this->dispatcher->dispatch($apiLog);
        } catch (\Throwable $e) {
            // 日志写入失败不影响业务请求
            $this->app->get(\Psr\Log\LoggerInterface::class)->error(
                '[ApiLogger] 中间件记录日志失败: ' . $e->getMessage(),
                ['exception' => $e]
            );
        } finally {
            // 确保刷新缓冲区
            try {
                $this->dispatcher->flush();
            } catch (\Throwable $e) {
                $this->app->get(\Psr\Log\LoggerInterface::class)->error(
                    '[ApiLogger] 中间件 flush 失败: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        return $response;
    }

    /**
     * 检查是否应跳过此请求
     */
    private function shouldSkip(Request $request): bool
    {
        $config = $this->app->config->get('wise-api.logger', []);

        // 检查跳过的 HTTP 方法
        $skipMethods = $config['skip_methods'] ?? ['OPTIONS'];
        $method = strtoupper($request->method());
        if (in_array($method, $skipMethods, true)) {
            return true;
        }

        // 检查跳过的路由
        $skipRoutes = $config['skip_routes'] ?? [];
        $route = '';
        try {
            $routeRule = $request->rule();
            if ($routeRule !== null) {
                $route = $routeRule->getName() ?? '';
            }
        } catch (\Throwable $e) {
            $route = '';
        }
        if (!empty($route) && in_array($route, $skipRoutes, true)) {
            return true;
        }

        // 检查跳过的路径（严格段前缀：整段相等，或以 '/' 分隔开头）
        // 原实现用裸前缀比较，'/health' 会顺带跳过 '/healthz-internal-admin'，
        // 造成内部路由的审计缺口。
        $skipPaths = $config['skip_paths'] ?? ['/health', '/metrics'];
        $path = '/' . ltrim($request->pathinfo(), '/');
        foreach ($skipPaths as $skipPath) {
            $skipPath = '/' . trim((string) $skipPath, '/');
            if ($skipPath === '/') {
                continue;
            }
            if ($path === $skipPath || str_starts_with($path, $skipPath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 构建 ApiLog DTO
     *
     * @param Request $request 请求对象
     * @param Response $response 响应对象
     * @param int $durationMs 耗时（毫秒）
     */
    private function buildApiLog(Request $request, Response $response, int $durationMs): ApiLog
    {
        $config = $this->app->config->get('wise-api.logger', []);
        $maxBodyLength = $config['max_body_length'] ?? 65536;
        $sensitiveHeaders = $config['sensitive_headers'] ?? ['authorization', 'cookie', 'x-api-key'];

        // 获取请求头
        $requestHeaders = null;
        if (!empty($config['log_request_headers'])) {
            $requestHeaders = $this->filterHeaders($request->header(), $sensitiveHeaders);
        }

        // 获取响应头
        $responseHeaders = null;
        if (!empty($config['log_response_headers'])) {
            $responseHeaders = $this->filterHeaders($response->getHeader(), $sensitiveHeaders);
        }

        // 请求体截断 + 敏感字段脱敏（密码/令牌/密钥等不得明文落库）
        $requestBody = $this->truncateBody($request->getContent(), $maxBodyLength);
        if ($requestBody !== null) {
            $sensitiveBodyKeys = $config['sensitive_body_keys']
                ?? ['password', 'passwd', 'pwd', 'old_password', 'secret', 'token',
                    'access_token', 'refresh_token', 'private_key', 'credit_card'];
            $maskFn = function (&$value, $key) use ($sensitiveBodyKeys) {
                if (is_string($key) && in_array(strtolower($key), $sensitiveBodyKeys, true)) {
                    $value = '***';
                }
            };

            $decoded = json_decode($requestBody, true);
            if (is_array($decoded)) {
                // JSON 请求体（含嵌套对象）
                array_walk_recursive($decoded, $maskFn);
                $requestBody = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $requestBody;
            } elseif (stripos((string) $request->contentType(), 'application/x-www-form-urlencoded') !== false) {
                // 表单编码请求体：password=xxx&token=yyy
                parse_str($requestBody, $formData);
                if (!empty($formData)) {
                    array_walk_recursive($formData, $maskFn);
                    $requestBody = http_build_query($formData);
                }
            }
        }

        // P1: 响应体采集优化 — 默认仅记录错误响应体，避免大响应内存开销
        $responseBody = null;
        $logResponseBody = $config['log_response_body'] ?? 'error_only';
        if ($logResponseBody === true || ($logResponseBody === 'error_only' && $response->getCode() >= 400)) {
            $responseBody = $this->truncateBody($response->getContent(), $maxBodyLength);
        }

        // 从请求头读取标识
        // F7: 优先使用 TraceIdMiddleware 注入的 trace_id，统一全链路追踪
        $traceId = $request->trace_id ?? $request->header('x-trace-id', '');
        // request_id 优先使用调用方传入的 X-Request-Id，否则回退到 trace_id
        $requestId = $request->header('x-request-id', '') ?: $traceId;
        $appKey = $request->header('x-app-key', '') ?: ($config['default_app_key'] ?? '');

        // 获取路由标识
        $route = '';
        try {
            $routeRule = $request->rule();
            if ($routeRule !== null) {
                $route = $routeRule->getName() ?? $routeRule->getRoute() ?? '';
            }
        } catch (\Throwable $e) {
            $route = '';
        }

        // 获取查询参数
        $requestQuery = $request->param();
        // 移除路由变量等系统参数
        unset($requestQuery['s'], $requestQuery['middleware']);

        return new ApiLog([
            'request_id'       => $requestId ?: $this->generateRequestId(),
            'app_key'          => $appKey,
            'duration_ms'      => $durationMs,
            'request_ip'       => $request->ip(),
            'request_method'   => $request->method(),
            'request_url'      => $request->url(true),
            'request_route'    => $route,
            'request_headers'  => $requestHeaders,
            'request_body'     => $requestBody,
            'request_query'    => !empty($requestQuery) ? $requestQuery : null,
            'response_status'  => $response->getCode(),
            'response_headers' => $responseHeaders,
            'response_body'    => $responseBody,
            'user_id'          => $this->getUserId($request),
            'user_agent'       => $request->header('user-agent', ''),
            'trace_id'         => $traceId,
            'channel'          => $config['default_channel'] ?? 'api',
            'extra'            => null,
            'create_time'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 过滤敏感头
     *
     * @param array<string, string> $headers 头信息
     * @param array<int, string> $sensitiveHeaders 敏感头字段列表
     * @return array<string, string>
     */
    private function filterHeaders(array $headers, array $sensitiveHeaders): array
    {
        $result = [];
        $sensitiveMap = array_map('strtolower', $sensitiveHeaders);

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveMap, true)) {
                $result[$key] = '***';
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * 截断请求/响应体
     */
    private function truncateBody(?string $body, int $maxLength): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        if (mb_strlen($body, '8bit') <= $maxLength) {
            return $body;
        }

        return mb_substr($body, 0, $maxLength, '8bit') . '...[truncated]';
    }

    /**
     * 获取用户 ID
     *
     * 尝试从请求属性中获取已登录用户 ID
     */
    private function getUserId(Request $request): string
    {
        try {
            // 优先从 JWT wise_user 属性获取（已在中间件中解析，避免 Lazy Load）
            if (!empty($request->wise_user['id'])) {
                return (string) $request->wise_user['id'];
            }

            // 回退到请求属性
            $userId = $request->userId ?? $request->uid ?? '';
            if (!empty($userId)) {
                return (string) $userId;
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        return '';
    }

    /**
     * 生成请求 ID（如果请求头中没有提供）
     */
    private function generateRequestId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 慢请求检测：超阈值自动记录 WARN 日志
     *
     * 阈值优先级：路由分组 > 全局默认（1000ms）
     * 配置示例：
     *   'slow_request' => [
     *       'threshold_ms' => 1000,          // 全局阈值
     *       'groups' => [
     *           'export' => 5000,             // 导出类接口放宽
     *       ],
     *   ],
     */
    private function checkSlowRequest(Request $request, int $durationMs): void
    {
        $config  = $this->app->config->get('wise-api.logger.slow_request', []);
        $threshold = $config['threshold_ms'] ?? 0;
        if ($threshold <= 0) {
            return; // 未启用慢请求检测
        }

        // 路由分组阈值覆盖
        $groups = $config['groups'] ?? [];
        $path   = '/' . ltrim($request->pathinfo(), '/');
        foreach ($groups as $prefix => $groupThreshold) {
            if (str_starts_with($path, $prefix)) {
                $threshold = (int) $groupThreshold;
                break;
            }
        }

        if ($durationMs >= $threshold) {
            $this->app->get(\Psr\Log\LoggerInterface::class)->warning(
                sprintf(
                    '[SlowRequest] %s %s took %dms (threshold: %dms)',
                    $request->method(),
                    $path,
                    $durationMs,
                    $threshold
                ),
                [
                    'method'    => $request->method(),
                    'path'      => $path,
                    'ip'        => $request->ip(),
                    'duration_ms' => $durationMs,
                    'threshold_ms' => $threshold,
                    'user_agent' => $request->header('User-Agent', ''),
                ]
            );
        }
    }
}
