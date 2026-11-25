<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * 框架（平台协议）版本协商中间件
 *
 * 框架/协议版本号仅通过请求头协商，与插件 API 版本（URL /api/{plugin}/v{N}/...）相互独立、互不干扰：
 *   - 请求头：X-API-Protocol-Version: v1（可选，缺省用配置默认版本）
 *   - 注入：$request->protocol_version（区别于插件版本 $request->api_version）
 *   - 响应头：X-API-Protocol-Version（回显实际协商到的协议版本）
 *
 * 配置（config/wise-api.php）：
 *   'version' => [
 *       'enable'    => true,
 *       'header'    => 'X-API-Protocol-Version',
 *       'supported' => ['v1'],
 *       'default'   => 'v1',
 *   ],
 *
 * 说明：
 *   - 当前平台只有一套协议，未命中 supported 的请求头会回退到 default；
 *   - 未来协议 breaking 时，可在此按协议版本加载对应网关组件（信封/签名/鉴权等）。
 *
 * @package wise\api\middleware
 */
class ProtocolVersionMiddleware
{
    /** @var array{enable:bool,header:string,supported:string[],default:string} */
    protected array $config;

    public function __construct(protected App $app)
    {
        $this->config = $this->app->config->get('wise-api.version', []);
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (empty($this->config['enable'])) {
            return $next($request);
        }

        $header    = (string) ($this->config['header'] ?? 'X-API-Protocol-Version');
        $supported = (array) ($this->config['supported'] ?? ['v1']);
        $default   = (string) ($this->config['default'] ?? 'v1');

        $version = strtolower(trim((string) $request->header($header, '')));
        if ($version === '' || !in_array($version, $supported, true)) {
            $version = $default;
        }

        $request->protocol_version = $version;

        $response = $next($request);

        $response->header(['X-API-Protocol-Version' => $version]);

        return $response;
    }
}
