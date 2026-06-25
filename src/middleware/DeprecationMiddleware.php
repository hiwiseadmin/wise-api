<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * API 版本废弃中间件
 *
 * 在响应头中告知客户端当前 API 版本的废弃/迁移信息：
 *
 *   X-API-Version:        当前请求版本
 *   X-API-Deprecated:     true/false（是否已废弃）
 *   X-API-Sunset:         计划下线日期（ISO 8601）
 *   X-API-Latest-Version: 最新版本号
 *   X-API-Deprecation-Message: 废弃提示（可选）
 *   Sunset:               RFC 8594 标准头（同 X-API-Sunset）
 *
 * 配置示例（config/wise-api.php）：
 *
 *   'version' => [
 *       'supported'  => ['v1', 'v2', 'v3'],
 *       'default'    => 'v1',
 *       'deprecated' => [
 *           'v1' => [
 *               'sunset'  => '2027-01-01',
 *               'message' => 'v1 即将下线，请迁移到 v2',
 *           ],
 *       ],
 *   ],
 *
 * 中间件栈位置：建议放在鉴权之后、控制器之前
 *
 * @package wise\api\middleware
 */
class DeprecationMiddleware
{
    /**
     * @param App $app
     */
    public function __construct(
        protected App $app
    ) {
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        $versionConfig = $this->app->config('wise-api.version', []);
        if (!($versionConfig['enable'] ?? true)) {
            return $next($request);
        }

        // 从 URL 路径提取版本号：/api/v1/users → v1
        $currentVersion = $this->extractVersion($request->pathinfo());

        if (empty($currentVersion)) {
            $currentVersion = $versionConfig['default'] ?? 'v1';
        }

        /** @var Response $response */
        $response = $next($request);

        // 基础头
        $response->header('X-API-Version', $currentVersion);

        // 最新版本
        $supported = $versionConfig['supported'] ?? [];
        if (!empty($supported)) {
            $latest = end($supported);
            $response->header('X-API-Latest-Version', $latest);
        }

        // 废弃信息
        $deprecated = $versionConfig['deprecated'] ?? [];
        if (isset($deprecated[$currentVersion])) {
            $info = $deprecated[$currentVersion];

            $response->header('X-API-Deprecated', 'true');

            if (!empty($info['sunset'])) {
                $sunset = $this->formatSunset($info['sunset']);
                $response->header('X-API-Sunset', $sunset);
                $response->header('Sunset', $sunset);            // RFC 8594
            }

            if (!empty($info['message'])) {
                $response->header('X-API-Deprecation-Message', $info['message']);
            }

            // 如果已过 Sunset 日期，返回 410 Gone
            if (!empty($info['sunset']) && strtotime($info['sunset']) < time()) {
                $data = [
                    'code' => 410,
                    'msg'  => sprintf(
                        'API 版本 %s 已于 %s 停止服务，请升级到 %s',
                        $currentVersion,
                        $info['sunset'],
                        $latest ?? '最新版本'
                    ),
                ];
                return response()->code(410)->content(json_encode($data, JSON_UNESCAPED_UNICODE))
                    ->header('Content-Type', 'application/json; charset=utf-8');
            }
        }

        return $response;
    }

    /**
     * 从请求路径中提取 API 版本号
     *
     * 示例：api/v1/users → v1
     */
    private function extractVersion(string $pathinfo): string
    {
        $path = '/' . ltrim($pathinfo, '/');
        if (preg_match('#^/api/(v\d+)/#', $path, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * 格式化 Sunset 日期为 ISO 8601
     */
    private function formatSunset(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        return date('c', $ts);
    }
}
