<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\jwt\exception\ErrorCode as JwtErrorCode;

/**
 * API 层 JWT 鉴权中间件（wise-jwt 适配器）
 *
 * 不重复实现鉴权逻辑：将 wise-jwt 的完整管线（Guard 切换、多提取器、
 * 登录限流、黑名单/指纹/全局失效校验、自动续签）通过「错误响应器」和
 * 「认证成功钩子」接入 wise-api 的统一响应协议。
 *
 * 控制器级 opt-in 用法：
 * <pre>
 * protected $middleware = [
 *     \wise\api\middleware\JwtAuthMiddleware::class,          // 默认 guard（wise-jwt 配置 default）
 *     \wise\api\middleware\JwtAuthMiddleware::class . ':api', // 指定 guard
 * ];
 * </pre>
 *
 * 协议差异（对比 wise-jwt 内置裸格式 {code:HTTP,msg,data}）：
 *   - 缺少令牌 → {code:2000 UNAUTHORIZED, msg, data, request_id, timestamp} / HTTP 401
 *   - 令牌过期 → {code:2002 TOKEN_EXPIRED, ...} / HTTP 401
 *   - 令牌无效 → {code:2001 TOKEN_INVALID, ...} / HTTP 401
 *   - 登录限流 → {code:3000 RATE_LIMITED, ...} / HTTP 429（含 Retry-After）
 *
 * 认证通过后额外注入 $request->wise_user = ['id' => sub, ...payload]，
 * 供 ApiLogMiddleware::getUserId() 与 ApiController::currentUser() 直接读取。
 *
 * @package wise\api\middleware
 */
class JwtAuthMiddleware
{
    public function __construct(
        protected App $app,
        protected ResponseWrapper $wrapper,
    ) {
    }

    /**
     * 处理请求：委托给 wise-jwt\middleware\JwtAuth
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $auth = $this->app->make(\wise\jwt\middleware\JwtAuth::class, [], true);

        $auth->setErrorResponder(function (Request $request, array $ctx): Response {
            $jwtCode  = (int) ($ctx['code'] ?? JwtErrorCode::JWT_ERROR->value);
            $message  = (string) ($ctx['message'] ?? '认证失败');
            $retryAfter = (int) ($ctx['retry_after'] ?? 0);

            if ($jwtCode === JwtErrorCode::THROTTLED->value) {
                $response = $this->wrapper->error($message, [
                    'retry_after' => $retryAfter,
                ], ErrorCode::RATE_LIMITED);
                if ($retryAfter > 0) {
                    $response->header(['Retry-After' => (string) $retryAfter]);
                }
                return $response;
            }

            $code = match ($jwtCode) {
                JwtErrorCode::TOKEN_MISSING->value => ErrorCode::UNAUTHORIZED,
                JwtErrorCode::TOKEN_EXPIRED->value => ErrorCode::TOKEN_EXPIRED,
                default                            => ErrorCode::TOKEN_INVALID,
            };

            return $this->wrapper->error($message, [], $code);
        });

        $auth->setAuthenticatedHook(function (Request $request, array $payload, string $token): void {
            $userId = $payload['user_id'] ?? $payload['sub'] ?? null;
            $request->wise_user = array_merge($payload, ['id' => $userId]);
        });

        return $auth->handle($request, $next, $guard);
    }
}
