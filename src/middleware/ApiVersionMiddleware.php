<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * API 版本路由中间件 (F2)
 *
 * 实现真正的多版本 API 共存，支持三种版本识别策略：
 *
 *   1. URI 路径：/api/v1/users → version=v1
 *   2. 请求头：Accept-Version: v1
 *   3. 查询参数：?api_version=v1
 *
 * 优先级：URI > Header > Query > 默认版本
 *
 * 检测到的版本会注入到 $request->api_version，控制器可通过
 * $this->request->api_version 读取当前版本并实现差异化逻辑。
 *
 * 调用过时版本时自动添加废弃/迁移响应头：
 *   X-API-Version: v1
 *   X-API-Deprecated: true
 *   X-API-Latest-Version: v2
 *   Sunset: Sat, 01 Jan 2027 00:00:00 GMT
 *
 * 配置示例（config/wise-api.php）：
 *   'version' => [
 *       'enable'    => true,
 *       'strategy'  => 'uri',          // uri|header|query|auto（自动检测）
 *       'supported' => ['v1', 'v2'],
 *       'default'   => 'v1',
 *       'deprecated' => [
 *           'v1' => [
 *               'sunset'  => '2027-01-01',
 *               'message' => 'v1 即将下线，请升级到 v2',
 *           ],
 *       ],
 *   ],
 *
 * 用法（全局中间件或路由组）：
 *   ->middleware(\wise\api\middleware\ApiVersionMiddleware::class)
 *
 * @package wise\api\middleware
 */
class ApiVersionMiddleware
{
    /**
     * 版本检测优先级顺序
     */
    private const STRATEGY_ORDER = ['uri', 'header', 'query'];

    /** @var array{enable:bool,strategy:string,supported:string[],default:string,deprecated:array} */
    private array $config;

    public function __construct(protected App $app)
    {
        $this->config = $this->app->config->get('wise-api.version', []);
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!($this->config['enable'] ?? false)) {
            return $next($request);
        }

        // 1. 检测 API 版本
        $version = $this->detectVersion($request);
        $supported = $this->config['supported'] ?? [];
        $default   = $this->config['default'] ?? 'v1';

        // 2. 验证版本有效性
        if (!in_array($version, $supported, true)) {
            $version = $default;
        }

        // 3. 注入到请求（控制器可通过 $request->api_version 读取）
        $request->api_version = $version;

        /** @var Response $response */
        $response = $next($request);

        // 4. 添加版本响应头
        $response->header('X-API-Version', $version);

        // 5. 检查是否为废弃版本
        $deprecated = $this->config['deprecated'] ?? [];
        if (isset($deprecated[$version])) {
            $info = $deprecated[$version];
            $response->header('X-API-Deprecated', 'true');
            $latest = end($supported) ?: $default;
            $response->header('X-API-Latest-Version', $latest);

            if (!empty($info['sunset'])) {
                $sunsetTs = strtotime($info['sunset']);
                if ($sunsetTs) {
                    $response->header(
                        'Sunset',
                        gmdate('D, d M Y H:i:s', $sunsetTs) . ' GMT'
                    );

                    // 超过 Sunset 日期 → 410 Gone
                    if (time() >= $sunsetTs) {
                        return response()->code(410);
                    }
                }
            }
        }

        return $response;
    }

    /**
     * 检测请求中的 API 版本
     *
     * 策略：
     *   'auto' → 按优先级依次尝试 uri > header > query
     *   'uri'  → 仅从 URI 路径检测
     *   'header' → 仅从 Accept-Version 头检测
     *   'query' → 仅从 api_version 参数检测
     */
    private function detectVersion(Request $request): string
    {
        $strategy = $this->config['strategy'] ?? 'auto';
        $default  = $this->config['default'] ?? 'v1';

        if ($strategy === 'auto') {
            foreach (self::STRATEGY_ORDER as $s) {
                $v = $this->detectBy($s, $request);
                if ($v !== null) {
                    return $v;
                }
            }
            return $default;
        }

        return $this->detectBy($strategy, $request) ?? $default;
    }

    /**
     * 按指定策略检测版本
     */
    private function detectBy(string $strategy, Request $request): ?string
    {
        return match ($strategy) {
            'uri'    => $this->detectFromUri($request),
            'header' => $this->detectFromHeader($request),
            'query'  => $this->detectFromQuery($request),
            default  => null,
        };
    }

    /**
     * 从 URI 路径检测版本：/api/v1/users → v1
     */
    private function detectFromUri(Request $request): ?string
    {
        $path = '/' . trim($request->pathinfo(), '/');
        if (preg_match('#^/(?:api/)?v(\d+)/#', $path, $m)) {
            return 'v' . $m[1];
        }
        return null;
    }

    /**
     * 从 Accept-Version 请求头检测
     */
    private function detectFromHeader(Request $request): ?string
    {
        $header = $request->header('Accept-Version', '');
        if ($header !== '' && preg_match('/^v\d+/i', trim($header), $m)) {
            return strtolower($m[0]);
        }
        return null;
    }

    /**
     * 从查询参数检测：?api_version=v1
     */
    private function detectFromQuery(Request $request): ?string
    {
        $v = $request->get('api_version', '');
        if (is_string($v) && preg_match('/^v\d+$/', $v)) {
            return $v;
        }
        return null;
    }
}
