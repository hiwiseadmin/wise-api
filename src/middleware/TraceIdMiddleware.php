<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\Request;
use think\Response;
use wise\api\debug\DebugPanel;

/**
 * 请求 ID 全链路追踪中间件
 *
 * 功能：
 *   1. 从上游 X-Request-Id 请求头继承 → 否则生成 UUID v4
 *   2. 写入 $request->trace_id 供下游中间件/控制器使用
 *   3. 响应头回传 X-Request-Id + X-Trace-Id 便于全链路追踪
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
        // 1. 提取或生成 trace_id：优先继承上游 X-Request-Id
        $traceId = $request->header('X-Request-Id', '');

        if (empty($traceId)) {
            // 生成 UUID v4（不依赖外部库）
            $traceId = self::uuidV4();
        }

        // 2. 注入到 Request 对象，供下游使用
        $request->trace_id = $traceId;

        // 3. 记录到调试面板
        $this->debugPanel?->record('trace', [
            'trace_id'    => $traceId,
            'inherited'   => $request->header('X-Request-Id') ? true : false,
            'user_agent'  => $request->header('User-Agent', ''),
            'ip'          => $request->ip(),
        ]);

        /** @var Response $response */
        $response = $next($request);

        // 4. 响应头回传
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

        // 设置版本号（4）和变种（10xx）
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
