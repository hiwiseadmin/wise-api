<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;

/**
 * IP 白名单/黑名单中间件
 *
 * 支持：
 *   - 白名单模式（仅允许列表中的 IP 访问）
 *   - 黑名单模式（拒绝列表中的 IP 访问）
 *   - 单个 IP / IP 网段 / CIDR 格式
 *   - Redis 动态配置（实时生效，无需重启）
 *
 * 配置示例（config/wise-api.php）：
 *
 *   'ip_filter' => [
 *       'enable'       => true,
 *       'mode'         => 'blacklist',     // whitelist | blacklist
 *       'ips'          => ['192.168.1.100', '10.0.0.0/8'],
 *       'redis_key'    => 'wise_api:ip_filter',  // Redis 动态列表
 *       'redis_enable' => false,                 // 启用 Redis 动态加载
 *   ],
 *
 * 中间件栈位置：建议在中间件链最前端
 *
 * @package wise\api\middleware
 */
class IpWhitelistMiddleware
{
    /**
     * @param App             $app
     * @param ResponseWrapper $wrapper
     */
    public function __construct(
        protected App $app,
        protected ResponseWrapper $wrapper
    ) {
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->app->config('wise-api.ip_filter', []);
        if (!($config['enable'] ?? false)) {
            return $next($request);
        }

        $mode   = $config['mode'] ?? 'blacklist';
        $clientIp = $request->ip();

        // 合并静态配置 + Redis 动态配置
        $ips = $config['ips'] ?? [];
        if (!empty($config['redis_enable']) && !empty($config['redis_key'])) {
            $redisIps = $this->loadFromRedis($config['redis_key']);
            $ips = array_merge($ips, $redisIps);
        }

        $matched = $this->matchIp($clientIp, $ips);

        // 白名单模式：IP 不在列表中 → 拒绝
        if ($mode === 'whitelist' && !$matched) {
            return $this->wrapper->error(
                ErrorCode::IP_BLOCKED->value,
                sprintf('IP %s 不在白名单中，访问被拒绝', $clientIp),
                ['ip' => $clientIp, 'mode' => 'whitelist'],
                403
            );
        }

        // 黑名单模式：IP 在列表中 → 拒绝
        if ($mode === 'blacklist' && $matched) {
            return $this->wrapper->error(
                ErrorCode::IP_BLOCKED->value,
                sprintf('IP %s 在黑名单中，访问被拒绝', $clientIp),
                ['ip' => $clientIp, 'mode' => 'blacklist'],
                403
            );
        }

        return $next($request);
    }

    /**
     * 检查客户端 IP 是否匹配列表中的任意一条
     *
     * 支持格式：
     *   - 单个 IP：192.168.1.1
     *   - CIDR 网段：192.168.1.0/24
     *   - 范围（保留扩展）
     *
     * @param string   $clientIp 客户端 IP
     * @param string[] $rules    IP 规则列表
     */
    private function matchIp(string $clientIp, array $rules): bool
    {
        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (empty($rule)) {
                continue;
            }

            // 精确匹配
            if ($rule === $clientIp) {
                return true;
            }

            // CIDR 网段匹配
            if (str_contains($rule, '/')) {
                if ($this->cidrMatch($clientIp, $rule)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * CIDR 网段匹配
     *
     * 例：192.168.1.0/24 匹配 192.168.1.0 ~ 192.168.1.255
     *
     * @param string $ip   IP 地址
     * @param string $cidr CIDR 格式（如 192.168.1.0/24）
     */
    private function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $subnetLong = ip2long($subnet);
        $ipLong     = ip2long($ip);

        if ($subnetLong === false || $ipLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * 从 Redis 加载动态 IP 列表
     *
     * @param string $key Redis 键名
     * @return string[]
     */
    private function loadFromRedis(string $key): array
    {
        try {
            $cache = $this->app->cache;
            $value = $cache->get($key, []);
            return is_array($value) ? array_map('strval', $value) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
