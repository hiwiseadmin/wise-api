<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use wise\api\debug\DebugPanel;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\api\sign\SignVerifier;
use wise\api\traits\HasDebugSupport;
use wise\support\context\ContextualLogger;

/**
 * 签名验证中间件
 *
 * 默认从 query/header 读取所有参数（含 GET/POST），计算 HMAC-SHA256 签名后
 * 与请求中的 sign 字段比对。同时强制 timestamp 防重放，可选 nonce 防重放。
 *
 * 关闭：在 config('wise-api.sign.enable') = false
 *
 * 配置安全性检查在 handle() 中延迟执行：仅当实际收到签名请求时检查密钥，
 * 避免安装/启动阶段的告警噪声。
 *
 * @package wise\api\middleware
 */
class SignVerifyMiddleware
{
    use HasDebugSupport;
    /**
     * 默认签名密钥值（与 config/wise-api.php 中的默认值一致）
     */
    private const DEFAULT_SIGN_SECRET = 'please-change-me-in-production-sign-secret!';

    /** @var bool 是否已输出过默认密钥告警（每个请求周期仅告警一次） */
    private bool $defaultSecretWarned = false;

    public function __construct(
        protected App $app,
        protected SignVerifier $verifier,
        protected ResponseWrapper $wrapper,
        protected ?DebugPanel $debugPanel = null
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->app->config->get('wise-api.sign', []);
        if (!($config['enable'] ?? true)) {
            return $next($request);
        }

        $decryptedJson = $request->{PayloadEncryptMiddleware::ATTR_DECRYPTED_JSON} ?? null;
        if (is_string($decryptedJson) && $decryptedJson !== '') {
            $decrypted = json_decode($decryptedJson, true);
            $params = is_array($decrypted)
                ? array_merge($request->get(), $decrypted)
                : array_merge($request->get(), $request->post());
        } else {
            $decryptedJson = null;
            $params = array_merge($request->get(), $request->post());
            $contentType = $request->contentType();
            if ($contentType && stripos($contentType, 'application/json') !== false) {
                $json = json_decode($request->getContent(), true);
                if (is_array($json)) {
                    $params = array_merge($params, $json);
                }
            }
        }
        $secret = (string) ($config['secret'] ?? '');
        $algo   = (string) ($config['algorithm'] ?? 'sha256');
        $excludes = array_merge($config['excludes'] ?? ['sign'], [$config['app_key_field'] ?? 'app_key']);

        if (!empty($config['sign_body'])) {
            $contentType = $request->contentType();
            $rawBody = $decryptedJson ?? $request->getContent();
            if ($rawBody !== '' && $rawBody !== null) {
                $isJsonBody = $contentType && stripos($contentType, 'application/json') !== false;
                $isXmlBody  = $contentType && (
                    stripos($contentType, 'application/xml') !== false ||
                    stripos($contentType, 'text/xml') !== false
                );
                if ($isJsonBody || $isXmlBody) {
                    $hashAlgo = (string) ($config['sign_body_algo'] ?? 'md5');
                    $params['body_hash'] = hash($hashAlgo, $rawBody);
                }
            }
        }

        if (!$this->defaultSecretWarned && $secret === self::DEFAULT_SIGN_SECRET) {
            $this->defaultSecretWarned = true;

            ContextualLogger::warning(
                'wise-api: Sign secret is using default value "' . self::DEFAULT_SIGN_SECRET . '". Please change it in production!'
            );
        }

        $timestamp = (int) ($params['timestamp'] ?? 0);
        $nonce     = $params['nonce'] ?? null;
        $tolerance = (int) ($config['timestamp_ttl'] ?? 300);
        $checkNonce = (bool) ($config['enable_nonce'] ?? true);

        $ts = $this->verifier->verifyTimestampAndNonce($timestamp, $tolerance, $checkNonce, is_string($nonce) ? $nonce : null);
        if (!$ts['ok']) {
            $errCode = $ts['reason'] === 'nonce reused'
                ? ErrorCode::SIGN_NONCE_REUSED
                : ErrorCode::SIGN_TIMESTAMP_BAD;
            $response = $this->wrapper->error('签名校验失败：' . $ts['reason'], [], $errCode);
            $this->debugRecord($this->debugPanel, 'sign', ['reason' => $ts['reason']]);
            $this->debugAttach($this->debugPanel, $response);
            return $response;
        }

        $verifyResult = $this->verifier->verify($params, $secret, $algo, $excludes);

        $this->debugRecord($this->debugPanel, 'sign', [
            'params_count' => count($params),
            'algorithm'    => $algo,
            'raw_string'   => $verifyResult['raw_string'] ?? null,
            'local_sign'   => $verifyResult['local_sign'] ?? null,
            'reason'       => $verifyResult['reason'] ?? null,
        ]);

        if (!$verifyResult['ok']) {
            $response = $this->wrapper->error(
                '签名校验失败：' . ($verifyResult['reason'] ?? 'unknown'),
                [],
                ErrorCode::SIGN_INVALID
            );
            $this->debugAttach($this->debugPanel, $response);
            return $response;
        }

        return $next($request);
    }
}
