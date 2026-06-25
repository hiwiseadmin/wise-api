<?php
declare(strict_types=1);

namespace wise\api\rate;

use think\Cache;

/**
 * 令牌桶限流算法
 *
 * 通过 Redis Lua 脚本实现原子性令牌补充与消费：
 *   - KEYS[1] = last_refill_key
 *   - KEYS[2] = tokens_key
 *   - ARGV[1] = capacity（桶容量）
 *   - ARGV[2] = rate（每秒填充令牌数）
 *   - ARGV[3] = now（当前时间戳，秒，微秒部分 * 1e6 叠加）
 *   - ARGV[4] = ttl（key 过期时间，秒）
 *
 * 返回值：{allowed, remaining_tokens, retry_after_ms}
 *
 * @package wise\api\rate
 */
class TokenBucket
{
    /**
     * Lua 脚本（原子令牌桶）
     *
     * 使用 redis.call('time') 取服务器时间，避免多节点时钟漂移
     */
    public const LUA_SCRIPT = <<<'LUA'
local capacity = tonumber(ARGV[1])
local rate     = tonumber(ARGV[2])
local now      = tonumber(ARGV[3])
local ttl      = tonumber(ARGV[4])

local last_refill = tonumber(redis.call('GET', KEYS[1]) or now)
local tokens      = tonumber(redis.call('GET', KEYS[2]) or capacity)

-- 补充令牌
local delta = math.max(0, now - last_refill) * rate
tokens = math.min(capacity, tokens + delta)

local allowed = 0
local retry_after = 0
if tokens >= 1 then
    tokens = tokens - 1
    allowed = 1
else
    -- 计算需要等待多久才能补足 1 个令牌（毫秒）
    local need = 1 - tokens
    retry_after = math.ceil((need / rate) * 1000)
end

redis.call('SET', KEYS[1], now, 'EX', ttl)
redis.call('SET', KEYS[2], tokens, 'EX', ttl)

return {allowed, tostring(tokens), retry_after}
LUA;

    /**
     * 尝试获取一个令牌
     *
     * @param string $key      限流主键（不含前缀）
     * @param int    $capacity 桶容量
     * @param float  $rate     每秒填充速率
     * @return array{allowed:bool,remaining:float,retry_after_ms:int}
     */
    public function attempt(string $key, int $capacity, float $rate): array
    {
        $now      = microtime(true);
        $ttl      = max(60, (int) ceil($capacity / max(0.001, $rate)) * 2);
        $lastKey  = "rate:tb:{$key}:last";
        $tokenKey = "rate:tb:{$key}:tokens";

        // 使用 Redis handler 的 eval 方法（ThinkPHP 6 Cache facade 没有 eval）
        $result = Cache::store('redis')->handler()->eval(
            self::LUA_SCRIPT,
            [$lastKey, $tokenKey, (string) $capacity, (string) $rate, (string) $now, (string) $ttl],
            2
        );

        if (!is_array($result) || count($result) < 3) {
            // Redis 不可用时放行（fail-open），避免业务雪崩
            return ['allowed' => true, 'remaining' => $capacity, 'retry_after_ms' => 0];
        }

        return [
            'allowed'         => (int) $result[0] === 1,
            'remaining'       => (float) $result[1],
            'retry_after_ms'  => (int) $result[2],
        ];
    }
}
