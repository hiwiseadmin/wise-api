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

        // 解密后的明文优先（PayloadEncryptMiddleware 注入 ATTR_DECRYPTED_JSON）：
        // 加密请求的原始 body 是 _enc 信封，若在此解析密文，签名覆盖的就是密文而非业务参数，
        // 与 PayloadEncryptMiddleware「签名验证基于明文参数」的契约矛盾。
        $decryptedJson = $request->{PayloadEncryptMiddleware::ATTR_DECRYPTED_JSON} ?? null;
        if (is_string($decryptedJson) && $decryptedJson !== '') {
            $decrypted = json_decode($decryptedJson, true);
            $params = is_array($decrypted)
                ? array_merge($request->get(), $decrypted)
                : array_merge($request->get(), $request->post());
        } else {
            $decryptedJson = null;
            $params = array_merge($request->get(), $request->post());
            // 处理 JSON 请求体（PUT/PATCH 等可能以 application/json 传入）
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

        // S3: Body Sign — 对 JSON/XML 请求体计算 hash 加入签名
        if (!empty($config['sign_body'])) {
            $contentType = $request->contentType();
            // 加密请求的原始 body 是 _enc 信封：body_hash 必须基于业务明文计算，
            // 否则客户端对明文签名必然与本地的"密文 hash"失配。
            $rawBody = $decryptedJson ?? $request->getContent();
            if ($rawBody !== '' && $rawBody !== null) {
                // 仅对非表单类请求体签名
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

        // 检测未修改的默认签名密钥（从 Service::register() 移至此处，延迟到首次签名请求时告警）
        if (!$this->defaultSecretWarned && $secret === self::DEFAULT_SIGN_SECRET) {
            $this->defaultSecretWarned = true;
            trigger_error(
                'wise-api: Sign secret is using default value "' . self::DEFAULT_SIGN_SECRET . '". Please change it in production!',
                E_USER_WARNING
            );
        }

        // ---------- 1. timestamp + nonce 校验 ----------
        $timestamp = (int) ($params['timestamp'] ?? 0);
        $nonce     = $params['nonce'] ?? null;
        $tolerance = (int) ($config['timestamp_ttl'] ?? 300);
        $checkNonce = (bool) ($config['enable_nonce'] ?? true);

        $ts = $this->verifier->verifyTimestampAndNonce($timestamp, $tolerance, $checkNonce, is_string($nonce) ? $nonce : null);
        if (!$ts['ok']) {
            $errCode = $ts['reason'] === 'nonce reused'
                ? ErrorCode::SIGN_NONCE_REUSED
                : ErrorCode::SIGN_TIMESTAMP_BAD;
            /** @var Response $response */
            $response = $this->wrapper->error('签名校验失败：' . $ts['reason'], [], $errCode);
            $this->debugRecord($this->debugPanel, 'sign', ['reason' => $ts['reason']]);
            $this->debugAttach($this->debugPanel, $response);
            return $response;
        }

        // ---------- 2. 签名计算 + 比对 ----------
        $verifyResult = $this->verifier->verify($params, $secret, $algo, $excludes);

        $this->debugRecord($this->debugPanel, 'sign', [
            'params_count' => count($params),
            'algorithm'    => $algo,
            'raw_string'   => $verifyResult['raw_string'] ?? null,
            'local_sign'   => $verifyResult['local_sign'] ?? null,
            'reason'       => $verifyResult['reason'] ?? null,
        ]);

        if (!$verifyResult['ok']) {
            /** @var Response $response */
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
