<?php
declare(strict_types=1);

namespace wise\api\controller;

use wise\api\ApiController;
use wise\api\encrypt\KeyManager;
use wise\api\encrypt\SessionKeyStore;
use wise\api\response\ErrorCode;
use wise\support\crypto\OpenSslConfig;

/**
 * 加密密钥分发控制器
 *
 * 提供两个端点：
 *   1. GET /api/encrypt/public-key  — 获取服务端 RSA 公钥（客户端用于信封加密）
 *   2. POST /api/encrypt/handshake  — ECDH 密钥协商（会话密钥模式，可选）
 *
 * 安全说明：
 *   - 公钥端点可公开访问（公钥本身就是公开的）
 *   - handshake 端点建议配合 RateLimit 中间件限流
 *   - 密钥信息建议客户端缓存，减少请求频率
 *
 * @package wise\api\controller
 */
class EncryptController extends ApiController
{
    /**
     * 无需鉴权的中间件配置
     * 公钥分发端点不依赖登录状态
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * 提供构造函数注入依赖（供容器使用）
     */
    public function __construct(
        private ?KeyManager $keyManager = null,
        private ?SessionKeyStore $sessionStore = null
    ) {
        parent::__construct();
        $this->keyManager ??= app(KeyManager::class);
        $this->sessionStore ??= app(SessionKeyStore::class);
    }

    /**
     * GET /api/encrypt/public-key
     *
     * 返回当前活跃的 RSA 公钥信息，供客户端加密请求使用。
     *
     * 响应：
     *   {
     *     "code": 0,
     *     "msg": "success",
     *     "data": {
     *       "key_id": "kid-001",
     *       "public_key": "-----BEGIN PUBLIC KEY-----\n...",
     *       "algorithm": "RSA-OAEP-256",
     *       "encryption": "A256GCM"
     *     }
     *   }
     *
     * 客户端使用方式：
     *   1. 请求此端点获取 public_key 和 key_id
     *   2. 生成一次性 AES-256 密钥 + 随机 IV
     *   3. 用 public_key 和 RSA-OAEP-256 加密 AES 密钥
     *   4. 用 AES-256-GCM 加密请求体
     *   5. 组装 _enc 信封发送请求
     *
     * @return \think\response\Json
     */
    public function publicKey(): \think\response\Json
    {
        $config = config('wise-api.encryption', []);

        if (!($config['enable'] ?? false)) {
            return $this->error(
                '传输加密功能未启用',
                ['enable' => false],
                ErrorCode::ENCRYPT_DISABLED
            );
        }

        try {
            $activeKey = $this->keyManager->getActivePublicKey();
        } catch (\RuntimeException $e) {
            // 本端点无需鉴权，不回显配置层异常详情（可能含 key_id、密钥文件路径等内部信息）
            \think\facade\Log::error('[wise-api] 加密公钥加载失败: ' . $e->getMessage());
            return $this->error(
                '加密密钥配置异常，请联系管理员',
                [],
                ErrorCode::ENCRYPT_KEY_NOT_FOUND
            );
        }

        return $this->success('', [
            'key_id'     => $activeKey['key_id'],
            'public_key' => $activeKey['public_key'],
            'algorithm'  => $activeKey['algorithm'],
            'encryption' => $config['algorithm']['aes_alg'] ?? 'A256GCM',
            'mode'       => $config['mode'] ?? 'envelope',
        ])->header([
            'Cache-Control' => 'public, max-age=3600',
            'ETag'          => '"' . md5($activeKey['public_key']) . '"',
        ]);
    }

    /**
     * POST /api/encrypt/handshake
     *
     * ECDH 密钥协商（会话密钥模式）。
     *
     * 请求：
     *   {
     *     "client_public_key": "Base64 编码的 ECDH 公钥"
     *   }
     *
     * 响应：
     *   {
     *     "code": 0,
     *     "msg": "success",
     *     "data": {
     *       "session_id": "sess_abc123",
     *       "server_public_key": "Base64 编码的服务端 ECDH 公钥",
     *       "expires_in": 3600
     *     }
     *   }
     *
     * 后续请求使用 session_id 标识会话，PayloadEncryptMiddleware
     * 会在解密时自动从 Redis 获取共享密钥。
     *
     * 注意：此端点需要配合 session 模式（encryption.mode=session）使用。
     *
     * @return \think\response\Json
     */
    public function handshake(): \think\response\Json
    {
        $config = config('wise-api.encryption', []);

        if (!($config['enable'] ?? false)) {
            return $this->error(
                '传输加密功能未启用',
                [],
                ErrorCode::ENCRYPT_DISABLED
            );
        }

        $mode = $config['mode'] ?? 'envelope';
        if ($mode !== 'session') {
            return $this->error(
                '当前加密模式不支持密钥协商，请使用无状态模式（envelope）',
                ['current_mode' => $mode],
                ErrorCode::ENCRYPT_ALGO_UNSUPPORTED
            );
        }

        // 参数校验
        $params = $this->validateRequest([
            'client_public_key' => 'require',
        ]);

        $clientPublicKey = (string) $params['client_public_key'];

        // 生成服务端 ECDH 密钥对
        try {
            $serverKeyPair = $this->generateEcdhKeyPair();
        } catch (\RuntimeException $e) {
            return $this->error(
                '密钥协商失败：' . $e->getMessage(),
                [],
                ErrorCode::ENCRYPT_KEY_INVALID
            );
        }

        // 计算共享密钥
        $sharedSecret = $this->computeEcdhSharedSecret($serverKeyPair['private'], $clientPublicKey);
        if ($sharedSecret === null) {
            return $this->error(
                '密钥协商失败：无法计算共享密钥',
                [],
                ErrorCode::ENCRYPT_DECRYPT_FAILED
            );
        }

        // 生成会话 ID 并存储（通过 SessionKeyStore 统一管理）
        $sessionId = 'sess_' . bin2hex(random_bytes(16));
        $sessionTtl = $config['session']['ttl'] ?? 3600;

        // 从 shared_secret 派生 AES-256 密钥
        $aesKey = hash('sha256', $sharedSecret, true);

        if (!$this->sessionStore->store($sessionId, $sharedSecret, $aesKey, $sessionTtl)) {
            return $this->error(
                '会话存储失败：Redis 不可用',
                [],
                ErrorCode::ENCRYPT_KEY_INVALID
            );
        }

        return $this->success('', [
            'session_id'        => $sessionId,
            'server_public_key' => base64_encode($serverKeyPair['public']),
            'expires_in'         => $sessionTtl,
            'algorithm'          => 'ECDH-P256',
        ]);
    }

    // ── ECDH helpers ──

    /**
     * 生成 ECDH P-256 密钥对
     *
     * @return array{public: string, private: \OpenSSLAsymmetricKey}
     * @throws \RuntimeException
     */
    private function generateEcdhKeyPair(): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL extension is required for ECDH');
        }

        // Windows 需显式指定 openssl.cnf，否则会报
        // error:80000003:system library::No such process。
        $config = OpenSslConfig::withConfig([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('Failed to generate EC key pair: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new \RuntimeException('Failed to extract EC key details');
        }

        return [
            'public'  => $details['key'],
            'private' => $key,
        ];
    }

    /**
     * 计算 ECDH 共享密钥
     *
     * 使用 sodium_crypto_scalarmult 实现 ECDH（PHP 的 openssl 扩展
     * 没有原生 ECDH 函数，openssl_dh_compute_key 仅用于传统 DH）。
     *
     * @param \OpenSSLAsymmetricKey $serverPrivateKey
     * @param string                $clientPublicKeyBase64 Base64 编码的客户端 PEM 公钥
     * @return string|null 共享密钥的原始字节（32 字节），失败返回 null
     */
    private function computeEcdhSharedSecret(\OpenSSLAsymmetricKey $serverPrivateKey, string $clientPublicKeyBase64): ?string
    {
        $clientPublicKeyPem = @base64_decode($clientPublicKeyBase64, true);
        if ($clientPublicKeyPem === false || $clientPublicKeyPem === '') {
            return null;
        }

        $clientKey = @openssl_pkey_get_public($clientPublicKeyPem);
        if ($clientKey === false) {
            return null;
        }

        // PHP 7.2+ 内置 sodium，可直接使用 crypto_kx 或 scalarmult
        if (!extension_loaded('sodium')) {
            return null;
        }

        try {
            // 提取客户端公钥的原始坐标
            $details = openssl_pkey_get_details($clientKey);
            if (!$details || !isset($details['ec']['x'], $details['ec']['y'])) {
                return null;
            }

            // 构造未压缩公钥：04 || x || y（x,y 各 32 字节 for P-256）
            $clientX = pack('H*', str_pad($details['ec']['x'], 64, '0', STR_PAD_LEFT));
            $clientY = pack('H*', str_pad($details['ec']['y'], 64, '0', STR_PAD_LEFT));
            $clientRaw = "\x04" . $clientX . $clientY;

            // 提取服务端私钥的原始标量
            $serverDetails = openssl_pkey_get_details($serverPrivateKey);
            if (!$serverDetails || !isset($serverDetails['ec']['d'])) {
                return null;
            }
            $serverScalar = pack('H*', str_pad($serverDetails['ec']['d'], 64, '0', STR_PAD_LEFT));

            // ECDH: shared_secret = server_scalar * client_public_key
            $shared = sodium_crypto_scalarmult($serverScalar, $clientRaw);
            if ($shared === false) {
                return null;
            }

            return $shared;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
