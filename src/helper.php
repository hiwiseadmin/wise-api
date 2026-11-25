<?php
declare(strict_types=1);

/**
 * WiseApi 全局助手函数
 *
 * @package wise\api
 */

if (!function_exists('wise_api')) {
    /**
     * 快速获取包内组件（容器解析）
     *
     * 用法：
     *   wise_api('jwt')->issue($userId);
     *   wise_api('sign')->verify($params, $secret);
     *
     * @template T
     * @param class-string<T>|string $abstract  类名或别名
     * @return mixed
     */
    function wise_api(string $abstract)
    {
        $aliases = [
            'jwt'         => \wise\jwt\Jwt::class,
            'sign'        => \wise\api\sign\SignVerifier::class,
            'response'    => \wise\api\response\ResponseWrapper::class,
            'rate'        => \wise\api\rate\RateLimitCoordinator::class,
            'debug'       => \wise\api\debug\DebugPanel::class,
            'cipher'      => \wise\api\encrypt\Cipher::class,
            'key_manager' => \wise\api\encrypt\KeyManager::class,
        ];
        $class = $aliases[$abstract] ?? $abstract;
        return app($class);
    }
}

if (!function_exists('wise_api_response')) {
    /**
     * 快速获取统一响应包装器
     */
    function wise_api_response(): \wise\api\response\ResponseWrapper
    {
        return app(\wise\api\response\ResponseWrapper::class);
    }
}

if (!function_exists('wise_api_jwt')) {
    /**
     * 快速获取 JWT 核心类（委托给 wise-jwt）
     */
    function wise_api_jwt(): \wise\jwt\Jwt
    {
        return app(\wise\jwt\Jwt::class);
    }
}

if (!function_exists('wise_api_encrypt_request')) {
    /**
     * 加密请求体（服务端间调用）
     *
     * 用于 PHP 服务端作为客户端调用另一个 WiseAdmin API 时，
     * 对请求体进行信封加密。
     *
     * 用法：
     *   // 1. 获取目标服务的公钥
     *   $info = file_get_contents('https://api.example.com/api/encrypt/public-key');
     *   $keyInfo = json_decode($info, true)['data'];
     *
     *   // 2. 加密请求
     *   $encrypted = wise_api_encrypt_request(
     *       ['username' => 'test', 'action' => 'transfer'],
     *       $keyInfo['public_key'],
     *       $keyInfo['key_id']
     *   );
     *
     *   // 3. 发送请求
     *   $ch = curl_init('https://api.example.com/api/v1/xxx');
     *   curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($encrypted));
     *   curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
     *   $response = curl_exec($ch);
     *
     * @param array  $payload      待加密的业务数据
     * @param string $publicKeyPem 目标服务 RSA 公钥 PEM
     * @param string $keyId        密钥版本 ID
     * @return array{_enc: array}  加密信封（可直接 json_encode 发送）
     * @throws \RuntimeException 加密失败
     */
    function wise_api_encrypt_request(array $payload, string $publicKeyPem, string $keyId): array
    {
        $cipher = app(\wise\api\encrypt\Cipher::class);

        $envelope = $cipher->encrypt(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $publicKeyPem,
            $keyId
        );

        return $envelope->toArray();
    }
}

if (!function_exists('wise_api_decrypt_response')) {
    /**
     * 解密响应体（服务端间调用）
     *
     * 用于解密另一 WiseAdmin API 返回的加密响应。
     * 注意：需要使用加密请求时的同一 AES 密钥。
     *
     * @param array  $encryptedResponse 加密的响应体
     * @param string $aesKeyRaw         32 字节 AES 密钥（从加密请求中保留）
     * @return array 解密后的业务数据
     * @throws \RuntimeException 解密失败
     */
    function wise_api_decrypt_response(array $encryptedResponse, string $aesKeyRaw): array
    {
        if (!isset($encryptedResponse['_enc'])) {
            return $encryptedResponse;
        }

        $cipher = app(\wise\api\encrypt\Cipher::class);

        $enc = $encryptedResponse['_enc'];
        $plaintext = $cipher->decryptWithKey(
            (string) ($enc['ciphertext'] ?? ''),
            $aesKeyRaw,
            (string) ($enc['iv'] ?? ''),
            (string) ($enc['tag'] ?? '')
        );

        return json_decode($plaintext, true) ?: [];
    }
}

if (!function_exists('wise_api_encrypt_and_send')) {
    /**
     * 一键加密请求 + 发送 + 解密响应（服务端间调用）
     *
     * 封装完整的加密通信流程，用于 PHP 服务端调用另一个 WiseAdmin API。
     *
     * 用法：
     *   $result = wise_api_encrypt_and_send(
     *       'https://api.example.com/api/v1/users',
     *       ['name' => '张三', 'email' => 'zhangsan@example.com']
     *   );
     *
     * @param string $apiUrl      目标 API URL
     * @param array  $payload     业务数据
     * @param array  $curlOptions 额外 cURL 选项
     * @return array{code: int, msg: string, data: mixed} API 响应
     * @throws \RuntimeException 通信失败或加密异常
     */
function wise_api_encrypt_and_send(string $apiUrl, array $payload, array $curlOptions = []): array
{
    $parsed = parse_url($apiUrl);
    if (!isset($parsed['host'])) {
        throw new \RuntimeException("Invalid API URL: {$apiUrl}");
    }
    $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
    if (isset($parsed['port'])) {
        $baseUrl .= ':' . $parsed['port'];
    }
    $publicKeyUrl = $baseUrl . '/api/encrypt/public-key';

    $ch = curl_init($publicKeyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $keyResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $keyResponse === false) {
        throw new \RuntimeException("Failed to fetch public key from {$publicKeyUrl}: HTTP {$httpCode}");
    }

    $keyInfo = json_decode($keyResponse, true);
    if (!isset($keyInfo['data']['public_key'], $keyInfo['data']['key_id'])) {
        throw new \RuntimeException('Invalid public key response format');
    }

    $publicKey = $keyInfo['data']['public_key'];
    $keyId     = $keyInfo['data']['key_id'];

    $cipher = app(\wise\api\encrypt\Cipher::class);

    $result = $cipher->encryptAndReturnKey(
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $publicKey,
        $keyId
    );
    $envelope  = $result['envelope'];
    $aesKeyRaw = $result['aes_key'];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($envelope->toArray(), JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ] + $curlOptions);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        throw new \RuntimeException("API request failed: HTTP {$httpCode}");
    }

    $body = json_decode($response, true);
    if (!is_array($body)) {
        throw new \RuntimeException('Invalid API response: not JSON');
    }

    if (isset($body['_enc'])) {
        $enc = $body['_enc'];
        $plaintext = $cipher->decryptWithKey(
            (string) ($enc['ciphertext'] ?? ''),
            $aesKeyRaw,
            (string) ($enc['iv'] ?? ''),
            (string) ($enc['tag'] ?? '')
        );
        $body = json_decode($plaintext, true) ?: [];
    }

    return $body;
}
}
