<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Cache;
use think\Request;
use think\Response;
use wise\support\facade\Auth;

/**
 * 响应缓存中间件 (F1)
 *
 * 为读多写少的 API 提供服务端响应缓存，减少数据库查询压力。
 *
 * 特性：
 *   - 仅缓存 GET/HEAD 请求的成功响应（状态码 2xx）
 *   - 支持按路由分组的差异化 TTL
 *   - 自动设置 Cache-Control / Expires 响应头
 *   - 支持跳过认证请求（避免用户数据泄露）
 *   - 支持跳过指定路径/路由
 *
 * 中间件参数化：`:300` 表示 TTL=300 秒
 *
 * 配置示例（config/wise-api.php）：
 *   'cache_response' => [
 *       'enable' => false,
 *       'store'  => 'redis',          // 缓存驱动
 *       'prefix' => 'api_cache:',     // 缓存键前缀
 *       'default_ttl' => 300,         // 默认 TTL（秒）
 *       'skip_auth' => true,          // 跳过已认证请求
 *       'groups' => [                 // 按路由分组 TTL
 *           '/api/dict' => 3600,
 *           '/api/config' => 7200,
 *       ],
 *   ],
 *
 * 用法：
 *   ->middleware(\wise\api\middleware\CacheResponseMiddleware::class . ':600')
 *
 * @package wise\api\middleware
 */
class CacheResponseMiddleware
{
    /**
     * @param App   $app   ThinkPHP 应用实例
     * @param Cache $cache 缓存实例
     */
    public function __construct(
        protected App $app,
        protected Cache $cache
    ) {
    }

    /**
     * 处理请求
     *
     * @param Request     $request 请求对象
     * @param Closure     $next    下一个中间件
     * @param string      ...$params TTL 覆盖（秒）
     */
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        $config = $this->app->config->get('wise-api.cache_response', []);
        if (!($config['enable'] ?? false)) {
            return $next($request);
        }

        $method = strtoupper($request->method());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        if (!empty($config['skip_auth']) && $this->isAuthenticated($request)) {
            return $next($request);
        }

        if ($this->shouldSkip($request, $config)) {
            return $next($request);
        }

        $ttl = !empty($params) ? (int) $params[0] : $this->resolveTtl($request, $config);

        $cacheKey = $this->buildCacheKey($request, $config);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            $headers = array_merge(
                $cached['headers'] ?? [],
                [
                    'X-Cache'       => 'HIT',
                    'Cache-Control' => 'public, max-age=' . $ttl,
                    'Expires'       => gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT',
                ]
            );
            $response = response($cached['body'], $cached['code'] ?? 200, $headers);
            return $response;
        }

        $response = $next($request);
        assert($response instanceof Response, 'Expected Response from $next');

        $statusCode = $response->getCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            $cacheData = [
                'body'    => $response->getContent(),
                'code'    => $statusCode,
                'headers' => $response->getHeader(),
                'cached_at' => time(),
            ];

            try {
                $this->cache->set($cacheKey, $cacheData, $ttl);
            } catch (\Throwable $e) {
                $this->app->log->warning(
                    '[CacheResponse] 写入缓存失败: ' . $e->getMessage()
                );
            }
        }

        $cacheHeaders = [
            'X-Cache'       => 'MISS',
            'Cache-Control' => 'public, max-age=' . $ttl,
            'Expires'       => gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT',
        ];
        \call_user_func([$response, 'header'], $cacheHeaders);

        return $response;
    }

    /**
     * 检查请求是否包含认证信息
     *
     * 优先通过 AuthManager::check('api') 统一判断，
     * 回退到原始 header/cookie/属性检查（兼容未加载 wise-support 的场景）。
     */
    private function isAuthenticated(Request $request): bool
    {
        if (!empty($request->wise_user)) {
            return true;
        }

        if ($request->header('Authorization', '') !== '' || !empty($request->cookie())) {
            return true;
        }

        if (class_exists(Auth::class)) {
            try {
                return (bool) Auth::check('api');
            } catch (\Throwable $e) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否应跳过此请求
     */
    private function shouldSkip(Request $request, array $config): bool
    {
        $skipPaths = $config['skip_paths'] ?? [];
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

        $nocache = $request->get('cache', '');
        if ($nocache === '0' || $nocache === 'false') {
            return true;
        }

        $cacheControl = $request->header('Cache-Control', '');
        if (stripos($cacheControl, 'no-cache') !== false) {
            return true;
        }

        return false;
    }

    /**
     * 解析路由分组 TTL
     */
    private function resolveTtl(Request $request, array $config): int
    {
        $defaultTtl = (int) ($config['default_ttl'] ?? 300);
        $groups = $config['groups'] ?? [];
        $path = '/' . ltrim($request->pathinfo(), '/');

        foreach ($groups as $prefix => $groupTtl) {
            if (str_starts_with($path, $prefix)) {
                return (int) $groupTtl;
            }
        }

        return $defaultTtl;
    }

    /**
     * 构造缓存键
     *
     * 键包含：app prefix + method + path + query string hash，
     * 确保不同参数请求的缓存隔离。
     */
    private function buildCacheKey(Request $request, array $config): string
    {
        $prefix = rtrim((string) ($config['prefix'] ?? 'api_cache:'), ':');
        $method = strtoupper($request->method());

        $path = '/' . trim($request->pathinfo(), '/');

        $query = $request->get();
        ksort($query, SORT_STRING);
        $queryHash = !empty($query) ? ':' . md5(json_encode($query, JSON_UNESCAPED_UNICODE)) : '';

        $identity = $this->identityFingerprint($request);
        $variant  = substr(md5(implode('|', [
            (string) $request->header('Accept', ''),
            (string) $request->header('Accept-Language', ''),
            (string) $request->header('X-Api-Version', (string) $request->header('Api-Version', '')),
        ])), 0, 12);

        return $prefix . ':' . $method . ':' . $path . $queryHash . ':' . $identity . ':' . $variant;
    }

    /**
     * 调用方身份指纹
     *
     * 只取凭据的摘要，不落任何可反推的信息；无凭据时固定为 anon，
     * 保证匿名请求仍能共享缓存。
     *
     * @param Request $request 请求对象
     * @return string
     */
    private function identityFingerprint(Request $request): string
    {
        $auth = (string) $request->header('Authorization', '');
        $user = $request->wise_user['id'] ?? null;

        if ($auth === '' && $user === null) {
            return 'anon';
        }

        return substr(md5($auth . '|' . (string) $user), 0, 16);
    }
}
