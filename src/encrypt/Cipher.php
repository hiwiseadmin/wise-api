<?php

declare(strict_types=1);

namespace wise\api\encrypt;

use wise\support\crypto\AesGcm;

/**
 * 信封加密/解密核心
 *
 * 实现 RSA + AES-256-GCM 混合加密（信封加密模式）：
 *
 * 加密流程（客户端→服务端）：
 *   1. 生成一次性 AES-256 随机密钥 + 随机 IV
 *   2. AES-256-GCM 加密业务数据 `ciphertext + tag`
 *   3. RSA 公钥加密 AES 密钥 `encrypted_key`
 *   4. 返回 EncryptedEnvelope（含 alg/enc/key_id/encrypted_key/iv/tag/ciphertext）
 *
 * 解密流程（服务端）：
 *   1. 从 Envelope 提取 key_id，获取对应 RSA 私钥
 *   2. RSA 私钥解密 encrypted_key → 恢复 AES 密钥
 *   3. AES-256-GCM 解密 ciphertext → 恢复明文（自动验证 GCM tag）
 *
 * 轻量加密（请求密钥复用，用于响应加密）：
 * - encryptWithKey(): 使用已有 AES 密钥加密（无 RSA 开销）
 * - decryptWithKey(): 使用已有 AES 密钥解密
 *
 * 使用示例：
 * <pre>
 * $cipher = new Cipher(new AesGcm());
 *
 * // 加密
 * $envelope = $cipher->encrypt($plaintext, $rsaPublicKey, 'kid-001');
 *
 * // 解密
 * $plaintext = $cipher->decrypt($envelope, $rsaPrivateKey);
 *
 * // 响应加密（复用密钥）
 * $result = $cipher->encryptWithKey($responseJson, $aesKey);
 * </pre>
 *
 * @package wise\api\encrypt
 */
class Cipher
{
    private AesGcm $aesGcm;

    public function __construct(?AesGcm $aesGcm = null)
    {
        $this->aesGcm = $aesGcm ?? new AesGcm();
    }

    /**
     * 信封加密（同时返回 AES 密钥，供调用方解密响应）
     *
     * 与 encrypt() 功能相同，但额外返回本次加密使用的 AES 密钥原始字节。
     * 调用方可暂存此密钥用于解密服务端返回的加密响应。
     *
     * @return array{envelope: EncryptedEnvelope, aes_key: string}
     * @throws \RuntimeException 加密失败
     */
    public function encryptAndReturnKey(
        string $plaintext,
        string $rsaPublicKey,
        string $keyId,
        string $rsaAlg = EncryptedEnvelope::RSA_ALG_OAEP_256,
        string $aad = ''
    ): array {
        $aesKey = $this->aesGcm->generateKey();
        $iv     = $this->aesGcm->generateIv();

        $encrypted = $this->aesGcm->encrypt($plaintext, $aesKey, $iv, $aad);

        $padding = $this->getRsaPadding($rsaAlg);
        $encryptedKey = $this->rsaEncrypt($aesKey, $rsaPublicKey, $padding, $rsaAlg);

        $envelope = EncryptedEnvelope::fromRawBinaries(
            $rsaAlg,
            EncryptedEnvelope::AES_ALG_256GCM,
            $keyId,
            $encryptedKey,
            $iv,
            $encrypted['tag'],
            $encrypted['ciphertext']
        );

        return [
            'envelope' => $envelope,
            'aes_key'  => $aesKey,
        ];
    }

    /**
     * 信封加密
     *
     * 生成一次性 AES 密钥 → 用其加密 payload → 用 RSA 公钥加密 AES 密钥。
     *
     * @param string $plaintext   明文数据
     * @param string $rsaPublicKey RSA 公钥（PEM 格式）
     * @param string $keyId       密钥版本 ID
     * @param string $rsaAlg      RSA 填充算法（默认 RSA-OAEP-256）
     * @param string $aad         附加认证数据（AAD），用于绑定请求上下文
     * @return EncryptedEnvelope
     * @throws \RuntimeException 加密失败
     */
    public function encrypt(
        string $plaintext,
        string $rsaPublicKey,
        string $keyId,
        string $rsaAlg = EncryptedEnvelope::RSA_ALG_OAEP_256,
        string $aad = ''
    ): EncryptedEnvelope {
        $aesKey = $this->aesGcm->generateKey();
        $iv     = $this->aesGcm->generateIv();

        $encrypted = $this->aesGcm->encrypt($plaintext, $aesKey, $iv, $aad);

        $padding = $this->getRsaPadding($rsaAlg);
        $encryptedKey = $this->rsaEncrypt($aesKey, $rsaPublicKey, $padding, $rsaAlg);

        return EncryptedEnvelope::fromRawBinaries(
            $rsaAlg,
            EncryptedEnvelope::AES_ALG_256GCM,
            $keyId,
            $encryptedKey,
            $iv,
            $encrypted['tag'],
            $encrypted['ciphertext']
        );
    }

    /**
     * 信封解密
     *
     * RSA 私钥解密 AES 密钥 → AES-GCM 解密 payload → 自动验证 GCM tag。
     *
     * @param EncryptedEnvelope $envelope      加密信封
     * @param string            $rsaPrivateKey  RSA 私钥（PEM 格式）
     * @param string            $aad            附加认证数据（必须与加密时一致）
     * @return string 明文数据
     * @throws \RuntimeException 解密失败或认证标签不匹配
     */
    public function decrypt(EncryptedEnvelope $envelope, string $rsaPrivateKey, string $aad = ''): string
    {
        $padding = $this->getRsaPadding($envelope->getAlg());
        $aesKey  = $this->rsaDecrypt(
            $envelope->getEncryptedKeyRaw(),
            $rsaPrivateKey,
            $padding,
            $envelope->getAlg()
        );

        return $this->aesGcm->decrypt(
            $envelope->getCiphertextRaw(),
            $aesKey,
            $envelope->getIvRaw(),
            $envelope->getTagRaw(),
            $aad
        );
    }

    /**
     * 使用已有 AES 密钥加密（轻量模式，用于响应加密）
     *
     * 不需要 RSA 操作，直接使用 AES 密钥加密。
     *
     * @param string $plaintext 明文
     * @param string $aesKey    32 字节 AES 密钥（原始二进制）
     * @param string $aad       附加认证数据
     * @return array{ciphertext: string, iv: string, tag: string} Base64 编码的密文/IV/tag
     */
    public function encryptWithKey(string $plaintext, string $aesKey, string $aad = ''): array
    {
        $iv = $this->aesGcm->generateIv();
        $result = $this->aesGcm->encrypt($plaintext, $aesKey, $iv, $aad);

        return [
            'ciphertext' => base64_encode($result['ciphertext']),
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($result['tag']),
        ];
    }

    /**
     * 使用已有 AES 密钥解密（轻量模式）
     *
     * @param string $ciphertextBase64 Base64 编码的密文
     * @param string $aesKey           32 字节 AES 密钥（原始二进制）
     * @param string $ivBase64         Base64 编码的 IV
     * @param string $tagBase64        Base64 编码的 GCM tag
     * @param string $aad              附加认证数据
     * @return string 明文
     * @throws \InvalidArgumentException Base64 解码失败
     * @throws \RuntimeException 解密失败
     */
    public function decryptWithKey(
        string $ciphertextBase64,
        string $aesKey,
        string $ivBase64,
        string $tagBase64,
        string $aad = ''
    ): string {
        $rawCiphertext = base64_decode($ciphertextBase64, true);
        $rawIv         = base64_decode($ivBase64, true);
        $rawTag        = base64_decode($tagBase64, true);

        if ($rawCiphertext === false || $rawIv === false || $rawTag === false) {
            throw new \InvalidArgumentException('Invalid base64 encoding in decryptWithKey parameters');
        }

        return $this->aesGcm->decrypt(
            $rawCiphertext,
            $aesKey,
            $rawIv,
            $rawTag,
            $aad
        );
    }

    /**
     * 从加密信封中提取 AES 密钥（供响应加密复用）
     *
     * 重新执行 RSA 私钥解密以获取原始 AES 密钥，
     * 仅解密 32 字节（AES-256 key），RSA 操作开销 < 1ms。
     *
     * @param EncryptedEnvelope $envelope     加密信封
     * @param string            $rsaPrivateKey RSA 私钥 PEM
     * @return string|null AES 密钥原始字节，失败返回 null
     */
    public function extractAesKeyFromEnvelope(EncryptedEnvelope $envelope, string $rsaPrivateKey): ?string
    {
        try {
            return $this->rsaDecrypt(
                $envelope->getEncryptedKeyRaw(),
                $rsaPrivateKey,
                $this->getRsaPadding($envelope->getAlg()),
                $envelope->getAlg()
            );
        } catch (\Throwable $e) {
            return null;
        }
    }


    /**
     * RSA 公钥加密
     *
     * @param string $data      原始数据（≤ keySize - 2*hashSize - 2）
     * @param string $publicKey PEM 格式公钥
     * @param int    $padding   OpenSSL 填充常量
     * @param string $rsaAlg    算法标识（用于错误消息）
     * @return string 加密后的数据（原始二进制）
     * @throws \RuntimeException
     */
    private function rsaEncrypt(string $data, string $publicKey, int $padding, string $rsaAlg): string
    {
        $key = @openssl_pkey_get_public($publicKey);
        if ($key === false) {
            throw new \RuntimeException(
                "RSA encrypt: invalid public key. " . openssl_error_string()
            );
        }

        $encrypted = '';
        $success = @openssl_public_encrypt(
            $data,
            $encrypted,
            $key,
            $padding
        );

        if (!$success) {
            throw new \RuntimeException(
                "RSA encrypt ({$rsaAlg}): " . openssl_error_string()
            );
        }

        return $encrypted;
    }

    /**
     * RSA 私钥解密
     *
     * @param string $data       加密数据（原始二进制）
     * @param string $privateKey PEM 格式私钥
     * @param int    $padding    OpenSSL 填充常量
     * @param string $rsaAlg     算法标识（用于错误消息）
     * @return string 解密后的数据
     * @throws \RuntimeException
     */
    private function rsaDecrypt(string $data, string $privateKey, int $padding, string $rsaAlg): string
    {
        $key = @openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException(
                "RSA decrypt: invalid private key. " . openssl_error_string()
            );
        }

        $decrypted = '';
        $success = @openssl_private_decrypt(
            $data,
            $decrypted,
            $key,
            $padding
        );

        if (!$success) {
            throw new \RuntimeException(
                "RSA decrypt ({$rsaAlg}): " . openssl_error_string()
            );
        }

        return $decrypted;
    }

    /**
     * 获取 OpenSSL 填充常量
     *
     * @param string $rsaAlg
     * @return int OPENSSL_PKCS1_OAEP_PADDING
     */
    private function getRsaPadding(string $rsaAlg): int
    {
        return match ($rsaAlg) {
            EncryptedEnvelope::RSA_ALG_OAEP_256,
            EncryptedEnvelope::RSA_ALG_OAEP => OPENSSL_PKCS1_OAEP_PADDING,
            default => throw new \RuntimeException(
                "Unsupported RSA algorithm '{$rsaAlg}'"
            ),
        };
    }
}
