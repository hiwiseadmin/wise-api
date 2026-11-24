<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\Request;
use think\Response;
use wise\api\debug\DebugPanel;
use wise\support\context\RequestContext;

/**
 * 请求 ID 全链路追踪中间件
 *
 * 功能：
 *   1. **复用全局 request_id**（`wise\support\context\RequestContext`）；
 *      全局中间件缺失时才回退到「继承上游 X-Request-Id → 生成 UUID v4」
 *   2. 写入 $request->trace_id 供下游中间件/控制器使用
 *   3. 响应头回传 X-Request-Id + X-Trace-Id 便于全链路追踪
 *
 * 为什么不再自行生成：
 *   全局 `app\middleware\RequestId` 与本中间件各自生成一套 ID，会导致同一请求
 *   出现两个标识 —— API 日志、业务日志、AI 工具审计（`ai_agent_logs`）无法串联。
 *   现统一以 RequestContext 为唯一来源。
 *
 * 中间件栈位置：应放在栈最前端（ApiLogMiddleware 之前）
 *
 * 示例：
 *   protected $middleware = [
 *       \wise\api\middleware\TraceIdMiddleware::class,
 *       \wise\api\middleware\ApiLogMiddleware::class,
 *       // ... 其他中间件
 *   ];
 *
 * @package wise\api\middleware
 */
class TraceIdMiddleware
{
    /**
     * @param DebugPanel|null $debugPanel 调试面板（可选注入）
     */
    public function __construct(
        protected ?DebugPanel $debugPanel = null
    ) {
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = RequestContext::getRequestId();

        if ($traceId === '' || $traceId === 'unknown') {
            $traceId = (string) ($request->header('X-Request-Id', '') ?: self::uuidV4());
            RequestContext::setRequestId($traceId);
        }

        $request->trace_id = $traceId;

        $this->debugPanel?->record('trace', [
            'trace_id'    => $traceId,
            'inherited'   => $request->header('X-Request-Id') ? true : false,
            'user_agent'  => $request->header('User-Agent', ''),
            'ip'          => $request->ip(),
        ]);

        $response = $next($request);

        $response->header([
            'X-Request-Id' => $traceId,
            'X-Trace-Id'   => $traceId,
        ]);

        return $response;
    }

    /**
     * 生成 UUID v4（RFC 4122）
     *
     * 格式：xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     * 不依赖外部库，纯 PHP 实现。
     */
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}
