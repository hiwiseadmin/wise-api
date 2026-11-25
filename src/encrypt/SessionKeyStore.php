<?php
declare(strict_types=1);

namespace wise\api\encrypt;

use think\facade\Cache;
use wise\support\context\ContextualLogger;

/**
 * 会话密钥存储（Redis）
 *
 * 管理 ECDH 密钥协商产生的会话密钥，支持以下操作：
 *   - store():    存储会话密钥（含 TTL）
 *   - retrieve(): 获取会话的共享密钥
 *   - renew():    续期会话 TTL
 *   - revoke():   主动吊销会话
 *   - cleanup():  清理过期会话
 *
 * 存储格式（Redis Hash）：
 *   Key:   wise_api:enc:session:{session_id}
 *   Value: {
 *       "shared_secret":       "base64_encoded_shared_key",
 *       "created_at":          "1700000000",
 *       "last_used_at":        "1700000100",
 *       "aes_key":             "base64_base_aes_key"
 *   }
 *
 * 注意：
 *   - 会话模式仅在 encryption.mode=session 时使用
 *   - Redis 不可用时会话模式不可用，自动降级到 envelope 模式
 *   - 每个会话使用独立 AES 密钥，前向安全
 *
 * @package wise\api\encrypt
 */
class SessionKeyStore
{
    /**
     * Redis key 前缀（与配置 encryption.session.prefix 一致）
     */
    private string $prefix;

    /**
     * 会话默认 TTL（秒）
     */
    private int $defaultTtl;

    /**
     * @param array $config encryption.session 配置段
     */
    public function __construct(array $config = [])
    {
        $this->prefix     = $config['prefix'] ?? 'wise_api:enc:session:';
        $this->defaultTtl = $config['ttl'] ?? 3600;
    }

    /**
     * 存储会话密钥
     *
     * @param string $sessionId    会话 ID
     * @param string $sharedSecret 共享密钥（原始二进制）
     * @param string $aesKey       派生 AES 密钥（可选，若无则从 sharedSecret 派生）
     * @param int|null $ttl        自定义 TTL（null 使用默认值）
     * @return bool 是否成功
     */
    public function store(string $sessionId, string $sharedSecret, string $aesKey = '', ?int $ttl = null): bool
    {
        if (!$this->isRedisAvailable()) {
            ContextualLogger::warning("[SessionKeyStore] Redis unavailable, cannot store session {$sessionId}");
            return false;
        }

        try {
            $key = $this->prefix . $sessionId;
            $now = time();

            Cache::store('redis')->set($key, [
                'shared_secret' => base64_encode($sharedSecret),
                'aes_key'       => $aesKey !== '' ? base64_encode($aesKey) : '',
                'created_at'    => (string) $now,
                'last_used_at'  => (string) $now,
            ], $ttl ?? $this->defaultTtl);

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::error("[SessionKeyStore] Failed to store session {$sessionId}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取会话密钥
     *
     * @param string $sessionId 会话 ID
     * @return array{shared_secret: string, aes_key: string}|null 成功返回密钥信息，null 表示不存在或已过期
     */
    public function retrieve(string $sessionId): ?array
    {
        if (!$this->isRedisAvailable()) {
            return null;
        }

        try {
            $key   = $this->prefix . $sessionId;
            $value = Cache::store('redis')->get($key);

            if (!is_array($value) || empty($value['shared_secret'] ?? '')) {
                return null;
            }

            $this->touchLastUsed($sessionId, $value);

            $aesKey = '';
            if (!empty($value['aes_key'] ?? '')) {
                $aesKey = base64_decode($value['aes_key'], true) ?: '';
            }

            return [
                'shared_secret' => base64_decode($value['shared_secret'], true) ?: '',
                'aes_key'       => $aesKey,
            ];
        } catch (\Throwable $e) {
            ContextualLogger::warning("[SessionKeyStore] Failed to retrieve session {$sessionId}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * 续期会话 TTL
     *
     * @param string $sessionId 会话 ID
     * @param int|null $ttl     新 TTL（null 使用默认值）
     * @return bool
     */
    public function renew(string $sessionId, ?int $ttl = null): bool
    {
        if (!$this->isRedisAvailable()) {
            return false;
        }

        try {
            $key = $this->prefix . $sessionId;

            if (!Cache::store('redis')->has($key)) {
                return false;
            }

            Cache::store('redis')->setExpire($key, $ttl ?? $this->defaultTtl);
            $this->touchLastUsed($sessionId);

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[SessionKeyStore] Failed to renew session {$sessionId}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 吊销会话
     *
     * @param string $sessionId 会话 ID
     * @return bool
     */
    public function revoke(string $sessionId): bool
    {
        if (!$this->isRedisAvailable()) {
            return false;
        }

        try {
            $key = $this->prefix . $sessionId;
            Cache::store('redis')->delete($key);
            return true;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[SessionKeyStore] Failed to revoke session {$sessionId}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 检查会话是否存在且有效
     *
     * @param string $sessionId
     * @return bool
     */
    public function exists(string $sessionId): bool
    {
        if (!$this->isRedisAvailable()) {
            return false;
        }

        try {
            $key = $this->prefix . $sessionId;
            return Cache::store('redis')->has($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取活跃会话数（用于监控）
     *
     * @return int
     */
    public function count(): int
    {
        if (!$this->isRedisAvailable()) {
            return 0;
        }

        try {
            $pattern = $this->prefix . '*';
            $redis = Cache::store('redis')->handler();

            if ($redis instanceof \Redis) {
                $count = 0;
                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, $pattern, 100);
                    if ($keys !== false) {
                        $count += count($keys);
                    }
                } while ($iterator > 0);
                return $count;
            }

            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 清理过期会话（Redis 自动 TTL 过期，通常不需要手动清理）
     *
     * @return int 清理的会话数
     */
    public function cleanup(): int
    {
        return 0;
    }


    /**
     * 更新最后使用时间戳
     */
    /**
     * 更新最后使用时间戳
     *
     * @param string     $sessionId 会话 ID
     * @param array|null $value     retrieve() 已取到的值，传入可省掉一次 Redis GET
     */
    private function touchLastUsed(string $sessionId, ?array $value = null): void
    {
        try {
            $key   = $this->prefix . $sessionId;
            $value = $value ?? Cache::store('redis')->get($key);

            if (!is_array($value)) {
                return;
            }

            $lastUsedAt = (int) ($value['last_used_at'] ?? 0);
            if ($lastUsedAt > 0 && time() - $lastUsedAt < 60) {
                return;
            }

            $value['last_used_at'] = (string) time();
            $ttl = Cache::store('redis')->getHandler()->ttl($key);
            $effectiveTtl = $ttl > 0 ? $ttl : ($ttl === -1 ? 0 : $this->defaultTtl);
            Cache::store('redis')->set($key, $value, $effectiveTtl > 0 ? $effectiveTtl : null);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 检查 Redis 是否可用
     */
    private function isRedisAvailable(): bool
    {
        try {
            return Cache::store('redis')->handler() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
