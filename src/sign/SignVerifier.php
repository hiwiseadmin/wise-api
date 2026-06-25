<?php
declare(strict_types=1);

namespace wise\api\sign;

use think\Cache;

/**
 * 签名验证器
 *
 * 签名规则：
 *   1. 排除 sign / app_key 字段本身
 *   2. 按参数名升序排序
 *   3. 拼接为 key1=value1&key2=value2 格式
 *      - 标量值取原始字符串
 *      - 数组/对象使用 json_encode（保证一致）
 *   4. 追加 &app_secret={secret}
 *   5. 计算 hash_hmac(algorithm, $string, $secretKey)
 *
 * 时间戳防重放：
 *   - 请求必须包含 timestamp（秒级）
 *   - 误差超过 timestamp_ttl 即拒绝
 *
 * Nonce 防重放（可选）：
 *   - 请求包含 nonce 时，存入 Redis 5 分钟，重复即拒绝
 *
 * @package wise\api\sign
 */
class SignVerifier
{
    /**
     * 构造签名字符串（供业务侧主动签名时复用）
     *
     * @param array<string,mixed> $params
     * @param string[]            $excludes
     */
    public function buildSignString(array $params, array $excludes = ['sign']): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if (in_array($k, $excludes, true)) {
                continue;
            }
            // 仅保留标量与可 JSON 化值
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif ($v === null) {
                $v = '';
            } else {
                $v = (string) $v;
            }
            $filtered[$k] = $v;
        }

        ksort($filtered, SORT_STRING);

        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = $k . '=' . $v;
        }

        return implode('&', $parts);
    }

    /**
     * 计算签名
     *
     * @param array<string,mixed> $params
     * @param string[]            $excludes
     */
    public function sign(array $params, string $secret, string $algorithm = 'sha256', array $excludes = ['sign']): string
    {
        $base = $this->buildSignString($params, $excludes);
        $base .= '&app_secret=' . $secret;
        return hash_hmac($algorithm, $base, $secret);
    }

    /**
     * 验证签名
     *
     * @param array<string,mixed> $params   全部请求参数
     * @param string              $secret   app_secret
     * @param string[]            $excludes
     * @return array{ok:bool,reason?:string,local_sign?:string,raw_string?:string}
     */
    public function verify(array $params, string $secret, string $algorithm = 'sha256', array $excludes = ['sign']): array
    {
        $sign = $params['sign'] ?? '';
        if (!is_string($sign) || $sign === '') {
            return ['ok' => false, 'reason' => 'missing sign'];
        }

        $rawString  = $this->buildSignString($params, $excludes) . '&app_secret=' . $secret;
        $localSign  = hash_hmac($algorithm, $rawString, $secret);

        if (!hash_equals($localSign, $sign)) {
            return [
                'ok'         => false,
                'reason'     => 'sign mismatch',
                'local_sign' => $localSign,
                'raw_string' => $rawString,
            ];
        }

        return [
            'ok'         => true,
            'local_sign' => $localSign,
            'raw_string' => $rawString,
        ];
    }

    /**
     * 校验时间戳与 nonce
     *
     * @return array{ok:bool,reason?:string}
     */
    public function verifyTimestampAndNonce(int $timestamp, int $tolerance, bool $checkNonce, ?string $nonce): array
    {
        $now = time();
        if (abs($now - $timestamp) > $tolerance) {
            return ['ok' => false, 'reason' => 'timestamp out of range'];
        }

        if ($checkNonce) {
            if (!is_string($nonce) || $nonce === '') {
                return ['ok' => false, 'reason' => 'missing nonce'];
            }
            $key = 'sign:nonce:' . $nonce;
            // 使用 Redis handler 的 set 方法实现 NX 语义（ThinkPHP 6 Cache::set 不支持第4参数）
            $ok = Cache::store('redis')->handler()->set($key, 1, ['ex' => $tolerance, 'nx' => true]);
            if (!$ok) {
                return ['ok' => false, 'reason' => 'nonce reused'];
            }
        }

        return ['ok' => true];
    }
}
