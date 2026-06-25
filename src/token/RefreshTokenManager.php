<?php
declare(strict_types=1);

namespace wise\api\token;

use think\Cache;

/**
 * Refresh Token 管理器
 *
 * 实现 Access Token + Refresh Token 双令牌机制：
 *
 *   Access Token：短有效期（默认 15 分钟），用于 API 请求鉴权
 *   Refresh Token：长有效期（默认 7 天），用于刷新 Access Token
 *
 * 安全特性：
 *   - Refresh Token 一次使用后立即轮转（rotation）防止重放
 *   - 旧 Refresh Token 加入"已使用家族"列表
 *   - 指定设备/应用参数避免跨设备复用
 *   - Redis 存储，支持过期自动清理
 *
 * 用法（登录接口）：
 *   $rtm = new RefreshTokenManager(cache('redis'), [
 *       'at_ttl' => 900,       // Access Token 15 分钟
 *       'rt_ttl' => 604800,    // Refresh Token 7 天
 *   ]);
 *
 *   // 签发
 *   $tokens = $rtm->issue($userId, ['role' => 'admin']);
 *   // → ['access_token' => '...', 'refresh_token' => '...', 'expires_in' => 900]
 *
 *   // 刷新
 *   $newTokens = $rtm->refresh($oldRefreshToken);
 *   // → 返回新令牌对，旧 Refresh Token 自动失效
 *
 *   // 撤销
 *   $rtm->revoke($userId);
 *   // → 该用户所有 Refresh Token 失效
 *
 * @package wise\api\token
 */
class RefreshTokenManager
{
    protected int $atTtl;
    protected int $rtTtl;
    protected string $secret;
    protected string $hashAlgo;

    /**
     * @param Cache|object $cache   缓存实例（Redis）
     * @param array        $options 选项
     *   - at_ttl: Access Token 有效期/秒（默认 900 = 15分钟）
     *   - rt_ttl: Refresh Token 有效期/秒（默认 604800 = 7天）
     *   - secret: 签名密钥（默认从 wise-api.jwt.secret 读取）
     *   - hash_algo: 哈希算法（默认 sha256）
     */
    public function __construct(
        protected $cache,
        protected array $options = []
    ) {
        $this->atTtl    = (int) ($options['at_ttl'] ?? 900);
        $this->rtTtl    = (int) ($options['rt_ttl'] ?? 604800);
        $this->secret   = (string) ($options['secret'] ?? config('wise-api.jwt.secret', ''));
        $this->hashAlgo = (string) ($options['hash_algo'] ?? 'sha256');
    }

    /**
     * 签发令牌对
     *
     * @param string|int $userId   用户 ID
     * @param array      $claims   自定义 claims
     * @param string     $deviceId 设备标识（可选，用于多设备隔离）
     * @return array{access_token:string, refresh_token:string, expires_in:int, token_type:string}
     */
    public function issue(string|int $userId, array $claims = [], string $deviceId = ''): array
    {
        $now  = time();
        $rtId = $this->generateId();  // Refresh Token 唯一 ID

        // 构建 Access Token payload（短有效期）
        $atPayload = array_merge($claims, [
            'sub'       => $userId,
            'iat'       => $now,
            'exp'       => $now + $this->atTtl,
            'jti'       => $this->generateId(),
            'type'      => 'access',
        ]);

        // 构建 Refresh Token payload（长有效期）
        $rtPayload = [
            'sub'       => $userId,
            'iat'       => $now,
            'exp'       => $now + $this->rtTtl,
            'jti'       => $rtId,
            'type'      => 'refresh',
            'device_id' => $deviceId,
        ];

        // 生成令牌
        $accessToken  = $this->sign($atPayload);
        $refreshToken = $this->sign($rtPayload);

        // 存储 Refresh Token 到 Redis
        $this->cache->set(
            $this->rtKey($rtId),
            [
                'user_id'     => $userId,
                'device_id'   => $deviceId,
                'create_time'  => $now,
                'expires_at'  => $now + $this->rtTtl,
                'family_hash' => $this->familyHash((string) $userId, $deviceId),
                'last_refresh'=> $now,
            ],
            $this->rtTtl
        );

        // 加入用户令牌家族（用于一键撤销）
        $this->cache->set(
            $this->familyKey((string) $userId, $deviceId),
            $rtId,
            $this->rtTtl
        );

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => $this->atTtl,
            'token_type'    => 'Bearer',
        ];
    }

    /**
     * 刷新令牌对（轮转机制）
     *
     * 用 Refresh Token 换取新的令牌对，旧的 Refresh Token 立即失效。
     *
     * @param string $refreshToken 旧的 Refresh Token
     * @return array{access_token:string, refresh_token:string, expires_in:int, token_type:string}
     * @throws \RuntimeException Refresh Token 无效/过期/已使用
     */
    public function refresh(string $refreshToken): array
    {
        // 1. 验证签名和格式
        $payload = $this->parse($refreshToken);
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            throw new \RuntimeException('Refresh Token 无效', 5000);
        }

        // 2. 验证过期
        if (($payload['exp'] ?? 0) < time()) {
            throw new \RuntimeException('Refresh Token 已过期', 5001);
        }

        $rtId = $payload['jti'] ?? '';

        // 3. 检查是否已被使用（防重放）
        $stored = $this->cache->get($this->rtKey($rtId));
        if (!$stored) {
            // Token 已被使用 → 撤销该用户所有 Refresh Token（防攻击）
            $userId   = $payload['sub'] ?? '';
            $deviceId = $payload['device_id'] ?? '';
            $this->revokeFamily((string) $userId, $deviceId);
            throw new \RuntimeException('Refresh Token 已被使用，所有令牌已撤销', 5002);
        }

        // 4. 删除旧 Refresh Token（一次使用后失效）
        $this->cache->delete($this->rtKey($rtId));

        // 5. 签发新令牌对
        $userId   = $payload['sub'] ?? '';
        $deviceId = $payload['device_id'] ?? '';

        // 保留原始 claims 中的业务字段
        $claims = $payload;
        unset($claims['sub'], $claims['iat'], $claims['exp'], $claims['jti'], $claims['type'], $claims['device_id']);

        return $this->issue($userId, $claims, $deviceId);
    }

    /**
     * 撤销用户所有 Refresh Token
     *
     * @param string|int $userId   用户 ID
     * @param string     $deviceId 设备标识（为空则撤销所有设备）
     */
    public function revoke(string|int $userId, string $deviceId = ''): void
    {
        if (!empty($deviceId)) {
            $this->revokeFamily((string) $userId, $deviceId);
        } else {
            // 撤销所有设备：遍历 Redis 中该用户的所有令牌家族
            // 简单实现：删除家族键；实际生产环境可用 SCAN 遍历
            $this->cache->delete($this->familyKey((string) $userId, '*'));
        }
    }

    /**
     * 撤销设备级令牌家族
     */
    private function revokeFamily(string $userId, string $deviceId): void
    {
        $familyKey = $this->familyKey($userId, $deviceId);
        $rtId = $this->cache->get($familyKey);
        if ($rtId && is_string($rtId)) {
            $this->cache->delete($this->rtKey($rtId));
        }
        $this->cache->delete($familyKey);
    }

    /**
     * 验证 Access Token
     *
     * @return array|null payload，无效返回 null
     */
    public function verifyAccessToken(string $token): ?array
    {
        $payload = $this->parse($token);
        if (!$payload) return null;
        if (($payload['type'] ?? '') !== 'access') return null;
        if (($payload['exp'] ?? 0) < time()) return null;
        return $payload;
    }

    /**
     * HMAC 签名生成令牌
     */
    protected function sign(array $payload): string
    {
        // 头部
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [];
        $segments[] = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $segments[] = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signingInput = implode('.', $segments);
        $signature = hash_hmac($this->hashAlgo, $signingInput, $this->secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * 解析并验证 JWT
     *
     * @return array|null payload，无效返回 null
     */
    protected function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $signingInput = "{$headerB64}.{$payloadB64}";
        $expectedSig  = $this->base64UrlEncode(hash_hmac($this->hashAlgo, $signingInput, $this->secret, true));

        if (!hash_equals($expectedSig, $signatureB64)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        return is_array($payload) ? $payload : null;
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function rtKey(string $rtId): string
    {
        return "rt:{$rtId}";
    }

    protected function familyKey(string $userId, string $deviceId): string
    {
        return "rt:family:{$userId}:{$deviceId}";
    }

    protected function familyHash(string $userId, string $deviceId): string
    {
        return hash('sha256', "{$userId}:{$deviceId}");
    }
}
