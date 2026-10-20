<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\api\debug\DebugPanel;
use wise\api\encrypt\Cipher;
use wise\api\encrypt\EncryptedEnvelope;
use wise\api\encrypt\KeyManager;
use wise\api\encrypt\SessionKeyStore;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\api\traits\HasDebugSupport;

/**
 * 传输加密中间件
 *
 * 在请求和响应方向各执行一次操作：
 *
 * 请求方向（解密）：
 *   1. 检测请求体是否包含加密信封 (_enc)
 *   2. 如果包含：使用 RSA 私钥解密 AES 密钥 → AES-GCM 解密 payload → 替换请求体
 *   3. 如果路由要求加密但请求未加密 → 返回 400 ENCRYPT_DISABLED
 *   4. 将 AES 密钥暂存于请求属性，供响应加密复用
 *
 * 响应方向（加密）：
 *   1. 如果请求是加密的且配置 auto_encrypt=true → 使用请求 AES 密钥加密响应体
 *   2. 加密后设置 X-Encrypted: true 头，供 CompressionMiddleware 跳过
 *
 * 中间件栈位置：
 *   BodySizeLimit → PayloadEncrypt → RateLimit → SignVerify → JwtAuth
 *   解密后的明文传递给后续中间件，签名验证和限流基于明文参数。
 *
 * 关闭：config('wise-api.encryption.enable') = false
 *
 * @package wise\api\middleware
 */
class PayloadEncryptMiddleware
{
    use HasDebugSupport;

    /**
     * Request 属性名：解密后的 AES 密钥（用于响应加密）
     */
    public const ATTR_AES_KEY = 'encrypt_aes_key';

    /**
     * Request 属性名：解密后的原始请求体 JSON
     */
    public const ATTR_DECRYPTED_JSON = 'encrypt_decrypted_json';

    /**
     * 加密请求体的字段名（必须是 JSON 对象的顶层 key）
     */
    private const ENC_FIELD = '_enc';

    public function __construct(
        protected App $app,
        protected Cipher $cipher,
        protected KeyManager $keyManager,
        protected ResponseWrapper $wrapper,
        protected ?DebugPanel $debugPanel = null,
        protected ?SessionKeyStore $sessionStore = null
    ) {
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->app->config->get('wise-api.encryption', []);

        // 1. 检查是否启用
        if (!($config['enable'] ?? false)) {
            return $next($request);
        }

        // 2. 获取请求原始内容
        $rawBody  = $request->getContent();
        $bodyData = $this->parseJsonBody($rawBody);
        $isEncrypted = is_array($bodyData) && isset($bodyData[self::ENC_FIELD]);
        $mode = $config['mode'] ?? 'envelope';

        // 会话模式：通过 X-Encrypt-Session 头检测
        $sessionId  = null;
        if ($mode === 'session') {
            $sessionId = $request->header('X-Encrypt-Session');
        }

        // 3. 检查路由是否在 exclude 列表中
        $routePath = $this->getRoutePath($request);
        if ($this->isRouteExcluded($routePath, $config)) {
            // 排除的路由：即使全局启用也不处理加密
            return $next($request);
        }

        // 4. 请求方向：解密
        if ($isEncrypted && !empty($sessionId) && $this->sessionStore !== null) {
            // 会话模式解密
            try {
                $result = $this->decryptSessionRequest($bodyData, $sessionId);

                $this->injectDecryptedBodySimple($request, $result['plaintext']);

                // 暂存 AES 密钥用于响应加密
                if ($result['aes_key'] !== null) {
                    $request->{self::ATTR_AES_KEY} = $result['aes_key'];
                }

                $this->debugRecord($this->debugPanel, 'encrypt', [
                    'direction' => 'decrypt_session',
                    'session_id'=> $sessionId,
                    'mode'      => 'session',
                ]);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse(
                    '加密信封格式无效：' . $e->getMessage(),
                    [],
                    ErrorCode::ENCRYPT_FORMAT_INVALID
                );
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                $code = str_contains($msg, 'expired') || str_contains($msg, 'not found')
                    ? ErrorCode::ENCRYPT_SESSION_EXPIRED
                    : ErrorCode::ENCRYPT_DECRYPT_FAILED;

                return $this->errorResponse(
                    '解密失败：' . $msg,
                    [],
                    $code
                );
            }
        } elseif ($isEncrypted) {
            try {
                $envelope  = EncryptedEnvelope::fromArray($bodyData);
                $privateKey = $this->keyManager->getPrivateKey($envelope->getKeyId());

                if ($privateKey === null) {
                    return $this->errorResponse(
                        '解密失败：密钥 ID 不存在',
                        ['key_id' => $envelope->getKeyId()],
                        ErrorCode::ENCRYPT_KEY_NOT_FOUND
                    );
                }

                $plaintext = $this->cipher->decrypt($envelope, $privateKey);

                // 将解密后的明文注入请求（后续中间件/控制器使用）
                $this->injectDecryptedBody($request, $rawBody, $bodyData, $envelope, $plaintext);

                // 暂存 AES 密钥（用于响应加密），委托 Cipher 统一管理 RSA 操作
                $aesKey = $this->cipher->extractAesKeyFromEnvelope($envelope, $privateKey);
                if ($aesKey !== null) {
                    $request->{self::ATTR_AES_KEY} = $aesKey;
                }

                $this->debugRecord($this->debugPanel, 'encrypt', [
                    'direction'    => 'decrypt',
                    'key_id'       => $envelope->getKeyId(),
                    'alg'          => $envelope->getAlg(),
                    'enc'          => $envelope->getEnc(),
                    'ciphertext_len' => strlen($envelope->getCiphertextBase64()),
                    'plaintext_len'  => strlen($plaintext),
                ]);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse(
                    '加密信封格式无效：' . $e->getMessage(),
                    [],
                    ErrorCode::ENCRYPT_FORMAT_INVALID
                );
            } catch (\RuntimeException $e) {
                // 判断是解密失败还是 GCM 认证失败
                $msg = $e->getMessage();
                $code = str_contains($msg, 'decrypt failed')
                    ? ErrorCode::ENCRYPT_DECRYPT_FAILED
                    : ErrorCode::ENCRYPT_VERIFY_FAILED;

                return $this->errorResponse(
                    '解密失败：' . $msg,
                    [],
                    $code
                );
            }
        } elseif ($this->isRouteRequired($routePath, $config)) {
            // 路由强制要求加密，但请求未加密
            return $this->errorResponse(
                '此接口要求加密传输，请在请求中包含 _enc 加密信封',
                ['required_route' => $routePath],
                ErrorCode::ENCRYPT_DISABLED
            );
        }

        // 5. 执行后续中间件
        $response = $next($request);

        // 6. 响应方向：加密
        if ($this->shouldEncryptResponse($response, $config)) {
            $aesKey = $request->{self::ATTR_AES_KEY} ?? null;
            if ($aesKey !== null && !$this->isErrorResponse($response)) {
                try {
                    $response = $this->encryptResponse($response, $aesKey);
                    $this->debugRecord($this->debugPanel, 'encrypt', [
                        'direction'  => 'encrypt_response',
                        'body_len'   => strlen((string) $response->getContent()),
                    ]);
                } catch (\RuntimeException $e) {
                    // 响应加密失败不应阻断请求（已成功处理），仅记录日志
                    $this->app->log->warning(
                        '[PayloadEncrypt] Response encryption failed: ' . $e->getMessage()
                    );
                }
            }
        }

        // 7. 附加调试头
        $this->debugAttach($this->debugPanel, $response);

        return $response;
    }

    // ── Request Helpers ──

    /**
     * 解析请求体为 JSON
     *
     * @return array|null
     */
    private function parseJsonBody(string $rawBody): ?array
    {
        if (empty($rawBody)) {
            return null;
        }

        $data = json_decode($rawBody, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 将解密后的明文注入 ThinkPHP Request
     *
     * 解密后的 JSON 存入 request 属性 ATTR_DECRYPTED_JSON，
     * 下游中间件和控制器通过 ApiController::getDecryptedInput() 读取。
     *
     * ThinkPHP 的 Request 不支持直接替换 body，因此采用属性注入方式。
     * SignVerifyMiddleware 和 ApiController::validateRequest() 应优先
     * 从 ATTR_DECRYPTED_JSON 读取参数（当存在时）。
     */
    private function injectDecryptedBody(
        Request $request,
        string $rawBody,
        array $bodyData,
        EncryptedEnvelope $envelope,
        string $plaintext
    ): void {
        $request->{self::ATTR_DECRYPTED_JSON} = $plaintext;
    }

    /**
     * 获取当前路由路径
     */
    private function getRoutePath(Request $request): string
    {
        // ThinkPHP 6: $request->baseUrl() 或 $request->pathinfo()
        $path = $request->pathinfo();
        return '/' . ltrim($path, '/');
    }

    // ── Route Matching ──

    /**
     * 检查路由是否在排除列表中
     */
    private function isRouteExcluded(string $routePath, array $config): bool
    {
        $excludes = $config['routes']['exclude'] ?? [];
        // 排除列表用严格段匹配：'/api/encrypt/public-key' 不应顺带排除 'public-key-x'
        return $this->matchRoutePrefix($routePath, $excludes, true);
    }

    /**
     * 检查路由是否强制要求加密
     */
    private function isRouteRequired(string $routePath, array $config): bool
    {
        $required = $config['routes']['require'] ?? [];
        // 强制加密列表保持宽松前缀语义：宁可多要求加密，不可漏要求
        return $this->matchRoutePrefix($routePath, $required, false);
    }

    /**
     * 路由匹配
     *
     * @param string[] $prefixes
     * @param bool     $strict true = 严格段匹配（相等或以 '/' 分隔开头）；
     *                         false = 宽松前缀匹配（保持既有语义）
     */
    private function matchRoutePrefix(string $routePath, array $prefixes, bool $strict = false): bool
    {
        foreach ($prefixes as $prefix) {
            $prefix = '/' . trim((string) $prefix, '/');
            if ($prefix === '/') {
                continue;
            }

            if ($strict) {
                if ($routePath === $prefix || str_starts_with($routePath, $prefix . '/')) {
                    return true;
                }
                continue;
            }

            if (str_starts_with($routePath, $prefix)) {
                return true;
            }
        }
        return false;
    }

    // ── Response Helpers ──

    /**
     * 判断是否应对响应进行加密
     */
    private function shouldEncryptResponse(Response $response, array $config): bool
    {
        // 配置未启用自动加密
        if (!($config['response']['auto_encrypt'] ?? true)) {
            return false;
        }

        // 响应已经是加密格式（错误响应等）
        $content = (string) $response->getContent();
        $data = json_decode($content, true);
        if (is_array($data) && isset($data[self::ENC_FIELD])) {
            return false;
        }

        // 响应头已标记为加密
        if ($response->getHeader('X-Encrypted')) {
            return false;
        }

        // 跳过非 JSON 响应
        $contentType = $response->getHeader('Content-Type') ?? '';
        if (stripos((string) $contentType, 'application/json') === false) {
            return false;
        }

        return true;
    }

    /**
     * 判断是否为错误响应（不对错误响应进行加密，保持客户端可读）
     */
    private function isErrorResponse(Response $response): bool
    {
        $statusCode = $response->getCode();
        return $statusCode >= 400;
    }

    /**
     * 加密响应体
     *
     * 使用请求时协商的 AES 密钥加密响应 JSON，返回加密信封格式。
     */
    private function encryptResponse(Response $response, string $aesKey): Response
    {
        $plaintext = (string) $response->getContent();

        $enc = $this->cipher->encryptWithKey($plaintext, $aesKey);

        $encryptedBody = json_encode([
            '_enc' => [
                'enc'        => EncryptedEnvelope::AES_ALG_256GCM,
                'iv'         => $enc['iv'],
                'tag'        => $enc['tag'],
                'ciphertext' => $enc['ciphertext'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encryptedBody === false) {
            throw new \RuntimeException('Failed to encode encrypted response');
        }

        $response->content($encryptedBody);
        $response->header('X-Encrypted', 'true');
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Content-Length', (string) strlen($encryptedBody));

        // Vary: Accept-Encoding — 通知 CDN 响应因加密而异
        $response->header('Vary', 'Accept-Encoding');

        return $response;
    }

    /**
     * 构造错误响应
     */
    private function errorResponse(string $msg, array $data, ErrorCode $code): Response
    {
        $httpCode = $this->wrapper->httpCodeFor($code->value);

        $response = $this->wrapper->error($msg, $data, $code, $httpCode);
        $this->debugAttach($this->debugPanel, $response);

        return $response;
    }

    // ── Session Mode ──

    /**
     * 会话模式解密请求
     *
     * 从 Redis 获取共享密钥 → 用共享密钥解密请求体。
     * 会话模式下 _enc 信封仅包含 enc/iv/tag/ciphertext，不包含 encrypted_key。
     *
     * @param array  $bodyData  请求体数组（含 _enc 键）
     * @param string $sessionId 会话 ID
     * @return array{plaintext: string, aes_key: ?string}
     * @throws \RuntimeException 会话过期或解密失败
     */
    private function decryptSessionRequest(array $bodyData, string $sessionId): array
    {
        $enc = $bodyData[self::ENC_FIELD] ?? [];
        if (!is_array($enc)) {
            throw new \InvalidArgumentException('_enc field must be an object');
        }

        // 获取会话密钥
        $sessionData = $this->sessionStore->retrieve($sessionId);
        if ($sessionData === null) {
            throw new \RuntimeException('session not found or expired: ' . $sessionId);
        }

        // 派生 AES 密钥（优先使用存储的 aes_key，否则从 shared_secret 派生）
        $aesKey = $sessionData['aes_key'] ?? '';
        if ($aesKey === '' && !empty($sessionData['shared_secret'])) {
            // 从 shared_secret 派生 AES-256 密钥：SHA-256(shared_secret)
            $aesKey = hash('sha256', $sessionData['shared_secret'], true);
        }

        if ($aesKey === '') {
            throw new \RuntimeException('failed to derive AES key from session');
        }

        // 使用 AES 密钥解密
        $plaintext = $this->cipher->decryptWithKey(
            (string) ($enc['ciphertext'] ?? ''),
            $aesKey,
            (string) ($enc['iv'] ?? ''),
            (string) ($enc['tag'] ?? '')
        );

        return [
            'plaintext' => $plaintext,
            'aes_key'   => $aesKey,
        ];
    }

    /**
     * 简化版明文注入（无信封对象）
     *
     * 用于会话模式和不需要保留信封信息的场景。
     */
    private function injectDecryptedBodySimple(Request $request, string $plaintext): void
    {
        $request->{self::ATTR_DECRYPTED_JSON} = $plaintext;
    }
}
