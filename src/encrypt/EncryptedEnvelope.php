<?php

declare(strict_types=1);

namespace wise\api\encrypt;

/**
 * 加密信封 DTO（不可变值对象）
 *
 * 封装一次加密操作的所有数据，包括算法标识、加密后的 AES 密钥（RSA 加密）、
 * IV、GCM 认证标签和密文。
 *
 * 设计原则：
 * - 所有属性只读，构造后不可变
 * - fromArray() 反序列化，toArray() 序列化
 * - 字段名遵循加密协议约定（小写+下划线）
 *
 * 字段说明：
 * - alg：RSA 加密算法（RSA-OAEP-256）
 * - enc：AES 加密算法（A256GCM）
 * - key_id：RSA 密钥版本标识
 * - encrypted_key：Base64 编码的 RSA 加密后的 AES 密钥
 * - iv：Base64 编码的 12 字节随机 IV
 * - tag：Base64 编码的 16 字节 GCM 认证标签
 * - ciphertext：Base64 编码的密文
 *
 * @package wise\api\encrypt
 */
class EncryptedEnvelope
{
    /**
     * RSA 加密算法标识：RSA-OAEP + SHA-256（推荐）
     */
    public const RSA_ALG_OAEP_256 = 'RSA-OAEP-256';

    /**
     * RSA 加密算法标识：RSA-OAEP + SHA-1（兼容旧版）
     */
    public const RSA_ALG_OAEP = 'RSA-OAEP';

    /**
     * AES 加密算法标识：AES-256-GCM
     */
    public const AES_ALG_256GCM = 'A256GCM';

    /**
     * AES 加密算法标识：AES-128-GCM
     */
    public const AES_ALG_128GCM = 'A128GCM';

    private string $alg;
    private string $enc;
    private string $keyId;
    private string $encryptedKey;
    private string $iv;
    private string $tag;
    private string $ciphertext;

    /**
     * @param string $alg          RSA 加密算法
     * @param string $enc          AES 加密算法
     * @param string $keyId        密钥版本 ID
     * @param string $encryptedKey Base64 编码的 RSA 加密 AES 密钥
     * @param string $iv           Base64 编码的 IV
     * @param string $tag          Base64 编码的 GCM tag
     * @param string $ciphertext   Base64 编码的密文
     */
    public function __construct(
        string $alg,
        string $enc,
        string $keyId,
        string $encryptedKey,
        string $iv,
        string $tag,
        string $ciphertext
    ) {
        $this->alg          = $alg;
        $this->enc          = $enc;
        $this->keyId        = $keyId;
        $this->encryptedKey = $encryptedKey;
        $this->iv           = $iv;
        $this->tag          = $tag;
        $this->ciphertext   = $ciphertext;
    }

    /**
     * 从客户端 JSON 请求体反序列化
     *
     * 支持两种输入格式：
     * 1. 直接传入 _enc 数组：['alg' => ..., 'enc' => ..., ...]
     * 2. 传入包含 _enc 的完整请求：['_enc' => ['alg' => ..., ...]]
     *
     * @param array $data 待反序列化的数组
     * @return self
     * @throws \InvalidArgumentException 缺少必要字段时抛出
     */
    public static function fromArray(array $data): self
    {
        // 自动提取 _enc 子数组
        if (isset($data['_enc']) && is_array($data['_enc'])) {
            $data = $data['_enc'];
        }

        $required = ['alg', 'enc', 'key_id', 'encrypted_key', 'iv', 'tag', 'ciphertext'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new \InvalidArgumentException(
                    "Encrypted envelope missing required field: {$field}"
                );
            }
        }

        return new self(
            (string) $data['alg'],
            (string) $data['enc'],
            (string) $data['key_id'],
            (string) $data['encrypted_key'],
            (string) $data['iv'],
            (string) $data['tag'],
            (string) $data['ciphertext']
        );
    }

    /**
     * 从加密成功的原始二进制数据创建信封
     *
     * @param string $alg          RSA 算法
     * @param string $enc          AES 算法
     * @param string $keyId        密钥版本 ID
     * @param string $encryptedKey RSA 加密后的 AES 密钥（原始二进制）
     * @param string $iv           原始 12 字节 IV
     * @param string $tag          原始 16 字节 GCM tag
     * @param string $ciphertext   原始密文
     * @return self
     */
    public static function fromRawBinaries(
        string $alg,
        string $enc,
        string $keyId,
        string $encryptedKey,
        string $iv,
        string $tag,
        string $ciphertext
    ): self {
        return new self(
            $alg,
            $enc,
            $keyId,
            base64_encode($encryptedKey),
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($ciphertext)
        );
    }

    /**
     * 序列化为数组（用于 JSON 响应）
     *
     * @return array{_enc: array{alg: string, enc: string, key_id: string, encrypted_key: string, iv: string, tag: string, ciphertext: string}}
     */
    public function toArray(): array
    {
        return [
            '_enc' => $this->toFlatArray(),
        ];
    }

    /**
     * 序列化为扁平数组（不含 _enc 外层 key，内部使用）
     *
     * @return array{alg: string, enc: string, key_id: string, encrypted_key: string, iv: string, tag: string, ciphertext: string}
     */
    public function toFlatArray(): array
    {
        return [
            'alg'           => $this->alg,
            'enc'           => $this->enc,
            'key_id'        => $this->keyId,
            'encrypted_key' => $this->encryptedKey,
            'iv'            => $this->iv,
            'tag'           => $this->tag,
            'ciphertext'    => $this->ciphertext,
        ];
    }

    // ── Getters ──

    public function getAlg(): string
    {
        return $this->alg;
    }

    public function getEnc(): string
    {
        return $this->enc;
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    /**
     * 获取 Base64 编码的加密密钥
     */
    public function getEncryptedKeyBase64(): string
    {
        return $this->encryptedKey;
    }

    /**
     * 获取 Base64 编码的 IV
     */
    public function getIvBase64(): string
    {
        return $this->iv;
    }

    /**
     * 获取 Base64 编码的 GCM tag
     */
    public function getTagBase64(): string
    {
        return $this->tag;
    }

    /**
     * 获取 Base64 编码的密文
     */
    public function getCiphertextBase64(): string
    {
        return $this->ciphertext;
    }

    /**
     * 获取解码后的 RSA 加密的 AES 密钥（原始二进制）
     */
    public function getEncryptedKeyRaw(): string
    {
        return base64_decode($this->encryptedKey);
    }

    /**
     * 获取解码后的 IV（原始 12 字节）
     */
    public function getIvRaw(): string
    {
        return base64_decode($this->iv);
    }

    /**
     * 获取解码后的 GCM tag（原始 16 字节）
     */
    public function getTagRaw(): string
    {
        return base64_decode($this->tag);
    }

    /**
     * 获取解码后的密文（原始二进制）
     */
    public function getCiphertextRaw(): string
    {
        return base64_decode($this->ciphertext);
    }
}
