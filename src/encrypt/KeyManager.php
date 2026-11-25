<?php

declare(strict_types=1);

namespace wise\api\encrypt;

use think\facade\Cache;
use wise\support\context\ContextualLogger;
use wise\support\crypto\OpenSslConfig;

/**
 * RSA 密钥管理器
 *
 * 管理加密所需的 RSA 密钥对，支持多密钥版本（key_id）和密钥缓存。
 *
 * 密钥加载优先级：
 *   1. 配置中的直接 PEM 文本
 *   2. 配置中的文件路径（以 "-----BEGIN" 开头判定为 PEM 文本，否则视为文件路径）
 *   3. Redis 缓存（TTL 可配，减少文件 IO）
 *
 * 密钥轮换：
 * - 活跃密钥（active_key_id）用于加密新请求
 * - 其它 key_id 仅用于解密已签名的历史数据
 * - 轮换时只需添加新 key_pair 并更新 active_key_id
 *
 * 使用示例：
 * <pre>
 * $km = new KeyManager($config);
 * $km->loadKeys();
 *
 * // 获取公钥分发给客户端
 * ['key_id' => $kid, 'public_key' => $pem] = $km->getActivePublicKey();
 *
 * // 解密客户端请求
 * $privateKey = $km->getPrivateKey($envelope->getKeyId());
 * </pre>
 *
 * @package wise\api\encrypt
 */
class KeyManager
{
    /**
     * 配置
     *
     * @var array
     */
    private array $config;

    /**
     * 已加载的密钥对（key_id => ['public' => PEM, 'private' => PEM]）
     *
     * @var array<string, array{public: string, private: string}>
     */
    private array $keys = [];

    /**
     * 是否已加载密钥
     */
    private bool $loaded = false;

    /**
     * 密钥缓存 Redis key 前缀
     */
    private const CACHE_PREFIX = 'wise_api:enc:key:';

    /**
     * 密钥缓存 TTL（秒）
     */
    private const CACHE_TTL = 3600;

    /**
     * 生成新 RSA 密钥对
     *
     * 使用 OpenSSL 生成 RSA 密钥对，供初始化或密钥轮换使用。
     * 此方法仅输出生成的密钥信息，不修改配置文件。
     *
     * Windows 环境下自动探测 openssl.cnf 路径。
     *
     * @param int $bits 密钥长度（2048 或 4096）
     * @return array{public_key: string, private_key: string} PEM 格式的密钥对
     * @throws \RuntimeException OpenSSL 扩展不可用或生成失败
     */
    public static function generateKeyPair(int $bits = 2048): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL extension is required for key generation');
        }

        $config = OpenSslConfig::withConfig([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException(
                'Failed to generate RSA key pair: ' . openssl_error_string()
            );
        }

        $privateKey = '';
        if (!openssl_pkey_export($key, $privateKey, null, $config) || !is_string($privateKey) || $privateKey === '') {
            throw new \RuntimeException(
                'Failed to export private key: ' . openssl_error_string()
            );
        }

        $publicKey = openssl_pkey_get_details($key);
        if ($publicKey === false || !isset($publicKey['key']) || $publicKey['key'] === '') {
            throw new \RuntimeException('Failed to extract public key details');
        }

        return [
            'public_key'  => $publicKey['key'],
            'private_key' => $privateKey,
        ];
    }

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 批量加载配置中所有密钥对
     *
     * 优先从 Redis 缓存读取，缓存未命中则从配置/文件加载并写入缓存。
     *
     * @throws \RuntimeException 活跃密钥对不可用时抛出
     */
    public function loadKeys(): void
    {
        if ($this->loaded) {
            return;
        }

        $activeKeyId = $this->config['keys']['active_key_id'] ?? 'kid-001';
        $keyPairs    = $this->config['keys']['key_pairs'] ?? [];

        foreach ($keyPairs as $keyId => $pairConfig) {
            $publicPem  = $this->resolvePem($pairConfig['public_key'] ?? '');
            $privatePem = $this->resolvePem($pairConfig['private_key'] ?? '');

            if ($publicPem === '') {
                $publicPem = (string) ($this->getFromCache($keyId)['public'] ?? '');
            }

            if ($publicPem !== '' && !$this->validatePem($publicPem, false)) {
                ContextualLogger::warning("[KeyManager] Invalid public key for key_id: {$keyId}, skipping");
                continue;
            }
            if ($privatePem !== '' && !$this->validatePem($privatePem, true)) {
                ContextualLogger::warning("[KeyManager] Invalid private key for key_id: {$keyId}, skipping");
                continue;
            }

            $this->keys[$keyId] = [
                'public'  => $publicPem,
                'private' => $privatePem,
            ];

            if ($publicPem !== '' && $this->getFromCache($keyId) === null) {
                $this->storeToCache($keyId, ['public' => $publicPem, 'private' => '']);
            }
        }

        if (!isset($this->keys[$activeKeyId])) {
            throw new \RuntimeException(
                "Active encryption key '{$activeKeyId}' not found in configuration"
            );
        }

        if (empty($this->keys[$activeKeyId]['private'])) {
            throw new \RuntimeException(
                "Active encryption key '{$activeKeyId}' has no private key configured"
            );
        }

        $this->loaded = true;
    }

    /**
     * 获取活跃密钥的公钥（供客户端加密请求）
     *
     * @return array{key_id: string, public_key: string, algorithm: string}
     * @throws \RuntimeException 密钥尚未加载
     */
    public function getActivePublicKey(): array
    {
        $this->ensureLoaded();

        $activeKeyId = $this->config['keys']['active_key_id'] ?? 'kid-001';
        $rsaAlg       = $this->config['algorithm']['rsa_alg'] ?? EncryptedEnvelope::RSA_ALG_OAEP_256;

        return [
            'key_id'     => $activeKeyId,
            'public_key' => $this->keys[$activeKeyId]['public'],
            'algorithm'  => $rsaAlg,
        ];
    }

    /**
     * 获取所有公钥列表（供客户端查看）
     *
     * @return array<int, array{key_id: string, public_key: string, algorithm: string}>
     */
    public function getAllPublicKeys(): array
    {
        $this->ensureLoaded();

        $rsaAlg = $this->config['algorithm']['rsa_alg'] ?? EncryptedEnvelope::RSA_ALG_OAEP_256;
        $result = [];

        foreach ($this->keys as $keyId => $keyData) {
            if (!empty($keyData['public'])) {
                $result[] = [
                    'key_id'     => $keyId,
                    'public_key' => $keyData['public'],
                    'algorithm'  => $rsaAlg,
                ];
            }
        }

        return $result;
    }

    /**
     * 根据 key_id 获取 RSA 私钥（PEM 格式）
     *
     * @param string $keyId 密钥版本 ID
     * @return string|null PEM 格式私钥，不存在时返回 null
     */
    public function getPrivateKey(string $keyId): ?string
    {
        $this->ensureLoaded();

        return $this->keys[$keyId]['private'] ?? null;
    }

    /**
     * 根据 key_id 获取 RSA 公钥（PEM 格式）
     *
     * @param string $keyId 密钥版本 ID
     * @return string|null PEM 格式公钥，不存在时返回 null
     */
    public function getPublicKey(string $keyId): ?string
    {
        $this->ensureLoaded();

        return $this->keys[$keyId]['public'] ?? null;
    }

    /**
     * 验证 PEM 格式的密钥是否有效
     *
     * @param string $pem       PEM 格式密钥
     * @param bool   $isPrivate 是否为私钥
     * @return bool
     */
    public function validatePem(string $pem, bool $isPrivate): bool
    {
        if (empty($pem)) {
            return false;
        }

        try {
            if ($isPrivate) {
                $key = openssl_pkey_get_private($pem);
            } else {
                $key = openssl_pkey_get_public($pem);
            }

            if ($key === false) {
                return false;
            }

            $details = openssl_pkey_get_details($key);
            if ($details === false || ($details['type'] ?? 0) !== OPENSSL_KEYTYPE_RSA) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取配置中的 RS256 算法对应的 OpenSSL 常量
     *
     * @param string $rsaAlg RSA 算法标识
     * @return int OPENSSL_PKCS1_OAEP_PADDING | OPENSSL_PKCS1_PADDING
     */
    public function getOpenSslPadding(string $rsaAlg): int
    {
        return match ($rsaAlg) {
            EncryptedEnvelope::RSA_ALG_OAEP_256,
            EncryptedEnvelope::RSA_ALG_OAEP => OPENSSL_PKCS1_OAEP_PADDING,
            default => OPENSSL_PKCS1_OAEP_PADDING,
        };
    }

    /**
     * 解析 PEM：区分直接文本和文件路径
     *
     * 规则：
     * - 以 "-----BEGIN" 开头 → 直接返回 PEM 文本
     * - 以 "/" 或 "file://" 开头 → 读取文件内容
     * - 其它 → 视为文件路径（相对或绝对）
     *
     * @param string $value 配置值
     * @return string PEM 内容
     */
    private function resolvePem(string $value): string
    {
        if (empty($value)) {
            return '';
        }

        if (str_starts_with($value, '-----BEGIN')) {
            return $value;
        }

        $path = $value;
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
        }

        if (!str_starts_with($path, '/') && !preg_match('#^[A-Z]:\\\\#i', $path)) {
            $path = app()->getRootPath() . $path;
        }

        if (!file_exists($path)) {
            ContextualLogger::warning("[KeyManager] Key file not found: {$path}");
            return '';
        }

        $content = file_get_contents($path);
        if ($content === false) {
            ContextualLogger::warning("[KeyManager] Failed to read key file: {$path}");
            return '';
        }

        return trim($content);
    }

    /**
     * 从 Redis 缓存读取密钥对
     *
     * @return array{public: string, private: string}|null
     */
    private function getFromCache(string $keyId): ?array
    {
        if (!$this->isCacheAvailable()) {
            return null;
        }

        try {
            $key   = self::CACHE_PREFIX . $keyId;
            $value = Cache::store('redis')->get($key);

            if (is_array($value) && isset($value['public'], $value['private'])) {
                return $value;
            }
        } catch (\Throwable $e) {
            ContextualLogger::debug("[KeyManager] Cache read error: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * 写入 Redis 缓存
     */
    private function storeToCache(string $keyId, array $data): void
    {
        if (!$this->isCacheAvailable()) {
            return;
        }

        try {
            $key = self::CACHE_PREFIX . $keyId;
            Cache::store('redis')->set($key, $data, self::CACHE_TTL);
        } catch (\Throwable $e) {
            ContextualLogger::debug("[KeyManager] Cache write error: {$e->getMessage()}");
        }
    }

    /**
     * 检查 Redis 缓存是否可用
     */
    private function isCacheAvailable(): bool
    {
        try {
            return Cache::store('redis')->handler() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 确保密钥已加载
     *
     * @throws \RuntimeException
     */
    private function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->loadKeys();
        }
    }

}
