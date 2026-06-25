<?php
declare(strict_types=1);

/**
 * WiseApi 全局助手函数
 *
 * @package wise\api
 */

if (!function_exists('wise_api')) {
    /**
     * 快速获取包内组件（容器解析）
     *
     * 用法：
     *   wise_api('jwt')->issue($userId);
     *   wise_api('sign')->verify($params, $secret);
     *
     * @template T
     * @param class-string<T>|string $abstract  类名或别名
     * @return mixed
     */
    function wise_api(string $abstract)
    {
        $aliases = [
            'jwt'         => \wise\jwt\Jwt::class,
            'sign'        => \wise\api\sign\SignVerifier::class,
            'response'    => \wise\api\response\ResponseWrapper::class,
            'rate'        => \wise\api\rate\RateLimiter::class,
            'debug'       => \wise\api\debug\DebugPanel::class,
        ];
        $class = $aliases[$abstract] ?? $abstract;
        return app($class);
    }
}

if (!function_exists('wise_api_response')) {
    /**
     * 快速获取统一响应包装器
     */
    function wise_api_response(): \wise\api\response\ResponseWrapper
    {
        return app(\wise\api\response\ResponseWrapper::class);
    }
}

if (!function_exists('wise_api_jwt')) {
    /**
     * 快速获取 JWT 核心类（委托给 wise-jwt）
     */
    function wise_api_jwt(): \wise\jwt\Jwt
    {
        return app(\wise\jwt\Jwt::class);
    }
}
