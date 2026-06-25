<?php
declare(strict_types=1);

namespace wise\api\middleware;

use think\Request;
use think\Response;
use wise\jwt\middleware\JwtAuth as WiseJwtAuth;

/**
 * JWT 鉴权中间件（wise-jwt 薄包装）
 * @deprecated 请直接使用 \wise\jwt\middleware\JwtAuth
 */
class JwtAuthMiddleware
{
    protected WiseJwtAuth $delegate;

    public function __construct()
    {
        $this->delegate = new WiseJwtAuth(
            app(\wise\jwt\Jwt::class),
            config('wise-jwt', [])
        );
    }

    public function handle(Request $request, \Closure $next): Response
    {
        return $this->delegate->handle($request, $next);
    }
}
