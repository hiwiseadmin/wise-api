<?php
declare(strict_types=1);

namespace wise\api\rate;

use think\Cache;

/**
 * 滑动窗口限流算法
 *
 * 通过 Redis Lua 脚本 + ZSET 实现原子滑动窗口：
 *   - KEYS[1] = window_zset_key
 *   - ARGV[1] = now（当前时间戳，微秒）
 *   - ARGV[2] = window（窗口大小，秒）
 *   - ARGV[3] = limit（最大请求数）
 *   - ARGV[4] = member（唯一请求标识，建议使用 nonce 或微秒时间戳）
 *   - ARGV[5] = ttl（key 过期时间 = window + 1）
 *
 * 返回值：{allowed, current_count, remaining}
 *
 * @package wise\api\rate
 */
class SlidingWindow
{
    /**
     * Lua 脚本（原子滑动窗口）
     */
    public const LUA_SCRIPT = <<<'LUA'
local now    = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local limit  = tonumber(ARGV[3])
local member = ARGV[4]
local ttl    = tonumber(ARGV[5])

-- 清理窗口外数据
local cutoff = now - window
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', cutoff)

local count = tonumber(redis.call('ZCARD', KEYS[1]))
local allowed = 0
if count < limit then
    redis.call('ZADD', KEYS[1], now, member)
    count = count + 1
    allowed = 1
end

redis.call('EXPIRE', KEYS[1], ttl)
return {allowed, count, limit - count}
LUA;

    /**
     * 尝试在窗口内通过一次请求
     *
     * @param string $key    限流主键
     * @param int    $limit  最大请求数
     * @param int    $window 窗口大小（秒）
     * @return array{allowed:bool,current:int,remaining:int}
     */
    public function attempt(string $key, int $limit, int $window): array
    {
        $now    = microtime(true);
        $ttl    = $window + 1;
        $member = $now . ':' . bin2hex(random_bytes(4));
        $zKey   = "rate:sw:{$key}";

        // 使用 Redis handler 的 eval 方法（ThinkPHP 6 Cache facade 没有 eval）
        $result = Cache::store('redis')->handler()->eval(
            self::LUA_SCRIPT,
            [$zKey, (string) $now, (string) $window, (string) $limit, $member, (string) $ttl],
            1
        );

        if (!is_array($result) || count($result) < 3) {
            return ['allowed' => true, 'current' => 0, 'remaining' => $limit];
        }

        return [
            'allowed'   => (int) $result[0] === 1,
            'current'   => (int) $result[1],
            'remaining' => (int) $result[2],
        ];
    }
}
