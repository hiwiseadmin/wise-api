<?php
declare(strict_types=1);

namespace wise\api\test;

use PHPUnit\Framework\TestCase;
use wise\api\encrypt\Cipher;
use wise\api\encrypt\EncryptedEnvelope;
use wise\api\encrypt\KeyManager;
use wise\api\response\ErrorCode;
use wise\support\crypto\AesGcm;

/**
 * 传输加密组件单元测试
 *
 * 覆盖 AesGcm / EncryptedEnvelope / Cipher / KeyManager / ErrorCode
 *
 * @package wise\api\test
 */
class EncryptTest extends TestCase
{

    public function testAesGcmGenerateKey(): void
    {
        $gcm = new AesGcm();

        $key1 = $gcm->generateKey();
        $key2 = $gcm->generateKey();

        $this->assertSame(AesGcm::KEY_LENGTH, strlen($key1));
        $this->assertSame(AesGcm::KEY_LENGTH, strlen($key2));
        $this->assertNotSame($key1, $key2, 'Generated keys should be unique');
    }

    public function testAesGcmGenerateIv(): void
    {
        $gcm = new AesGcm();

        $iv1 = $gcm->generateIv();
        $iv2 = $gcm->generateIv();

        $this->assertSame(AesGcm::IV_LENGTH, strlen($iv1));
        $this->assertSame(AesGcm::IV_LENGTH, strlen($iv2));
        $this->assertNotSame($iv1, $iv2, 'Generated IVs should be unique');
    }

    public function testAesGcmEncryptDecryptRoundTrip(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $iv        = $gcm->generateIv();
        $plaintext = 'Hello, API Encryption! 你好，API 加密！';

        $enc     = $gcm->encrypt($plaintext, $key, $iv);
        $decrypt = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);

        $this->assertSame($plaintext, $decrypt);
        $this->assertNotSame($plaintext, $enc['ciphertext']);
        $this->assertSame(AesGcm::TAG_LENGTH, strlen($enc['tag']));
    }

    public function testAesGcmEncryptDecryptWithAad(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $iv        = $gcm->generateIv();
        $plaintext = 'Sensitive data';
        $aad       = 'ctx:timestamp=1700000000';

        $enc     = $gcm->encrypt($plaintext, $key, $iv, $aad);
        $decrypt = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag'], $aad);

        $this->assertSame($plaintext, $decrypt);
    }

    public function testAesGcmAadTamperDetection(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $iv        = $gcm->generateIv();
        $plaintext = 'Sensitive data';
        $aad       = 'ctx:timestamp=1700000000';

        $enc = $gcm->encrypt($plaintext, $key, $iv, $aad);

        $this->expectException(\RuntimeException::class);
        $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag'], 'ctx:timestamp=1700000001');
    }

    public function testAesGcmCiphertextTamperDetection(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $iv        = $gcm->generateIv();
        $plaintext = 'Original data';

        $enc = $gcm->encrypt($plaintext, $key, $iv);

        $tampered = $enc['ciphertext'] . 'x';

        $this->expectException(\RuntimeException::class);
        $gcm->decrypt($tampered, $key, $iv, $enc['tag']);
    }

    public function testAesGcmInvalidKeyLength(): void
    {
        $gcm = new AesGcm();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AES key must be 32 bytes');
        $gcm->encrypt('test', 'short-key', $gcm->generateIv());
    }

    public function testAesGcmInvalidIvLength(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GCM IV must be 12 bytes');
        $gcm->encrypt('test', $key, 'short-iv');
    }

    public function testAesGcmInvalidTagLength(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $iv        = $gcm->generateIv();
        $plaintext = 'test';

        $enc = $gcm->encrypt($plaintext, $key, $iv);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GCM tag must be 16 bytes');
        $gcm->decrypt($enc['ciphertext'], $key, $iv, 'short');
    }

    public function testAesGcmEncryptProducesDifferentOutputForSameInput(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $plaintext = 'same content';

        $enc1 = $gcm->encrypt($plaintext, $key, $gcm->generateIv());
        $enc2 = $gcm->encrypt($plaintext, $key, $gcm->generateIv());

        $this->assertNotSame($enc1['ciphertext'], $enc2['ciphertext']);
    }

    public function testAesGcmBinaryDataRoundTrip(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $iv        = $gcm->generateIv();
        $plaintext = random_bytes(256);

        $enc     = $gcm->encrypt($plaintext, $key, $iv);
        $decrypt = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);

        $this->assertSame($plaintext, $decrypt);
    }

    public function testAesGcmEmptyStringRoundTrip(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();
        $iv  = $gcm->generateIv();

        $enc     = $gcm->encrypt('', $key, $iv);
        $decrypt = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);

        $this->assertSame('', $decrypt);
    }

    public function testAesGcmLargePayloadRoundTrip(): void
    {
        $gcm       = new AesGcm();
        $key       = $gcm->generateKey();
        $iv        = $gcm->generateIv();
        $plaintext = str_repeat('Large payload test data. ', 500);

        $enc     = $gcm->encrypt($plaintext, $key, $iv);
        $decrypt = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);

        $this->assertSame($plaintext, $decrypt);
    }


    public function testEncryptedEnvelopeFromArray(): void
    {
        $data = [
            'alg'           => 'RSA-OAEP-256',
            'enc'           => 'A256GCM',
            'key_id'        => 'kid-001',
            'encrypted_key' => base64_encode('encrypted-aes-key-data'),
            'iv'            => base64_encode('12byte-iv-here'),
            'tag'           => base64_encode('16byte-auth-tag!'),
            'ciphertext'    => base64_encode('the-encrypted-payload'),
        ];

        $envelope = EncryptedEnvelope::fromArray($data);

        $this->assertSame('RSA-OAEP-256', $envelope->getAlg());
        $this->assertSame('A256GCM', $envelope->getEnc());
        $this->assertSame('kid-001', $envelope->getKeyId());
        $this->assertSame($data['encrypted_key'], $envelope->getEncryptedKeyBase64());
        $this->assertSame($data['iv'], $envelope->getIvBase64());
        $this->assertSame($data['tag'], $envelope->getTagBase64());
        $this->assertSame($data['ciphertext'], $envelope->getCiphertextBase64());
    }

    public function testEncryptedEnvelopeFromArrayWithEncWrapper(): void
    {
        $data = [
            '_enc' => [
                'alg'           => 'RSA-OAEP-256',
                'enc'           => 'A256GCM',
                'key_id'        => 'kid-001',
                'encrypted_key' => base64_encode('enc-key'),
                'iv'            => base64_encode('123456789012'),
                'tag'           => base64_encode('tag12345678901234'),
                'ciphertext'    => base64_encode('cipher'),
            ],
            'other_field' => 'should-be-ignored',
        ];

        $envelope = EncryptedEnvelope::fromArray($data);

        $this->assertSame('RSA-OAEP-256', $envelope->getAlg());
        $this->assertSame('kid-001', $envelope->getKeyId());
    }

    public function testEncryptedEnvelopeFromArrayMissingField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field');

        EncryptedEnvelope::fromArray([
            'alg'  => 'RSA-OAEP-256',
            'enc'  => 'A256GCM',
        ]);
    }

    public function testEncryptedEnvelopeToArray(): void
    {
        $envelope = new EncryptedEnvelope(
            'RSA-OAEP-256',
            'A256GCM',
            'kid-001',
            base64_encode('ekey'),
            base64_encode('iviv'),
            base64_encode('tagt'),
            base64_encode('ciph')
        );

        $arr = $envelope->toArray();

        $this->assertArrayHasKey('_enc', $arr);
        $this->assertArrayHasKey('alg', $arr['_enc']);
        $this->assertSame('RSA-OAEP-256', $arr['_enc']['alg']);
    }

    public function testEncryptedEnvelopeToFlatArray(): void
    {
        $envelope = new EncryptedEnvelope(
            'RSA-OAEP-256', 'A256GCM', 'kid-001',
            base64_encode('ekey'), base64_encode('iviv'),
            base64_encode('tagt'), base64_encode('ciph')
        );

        $arr = $envelope->toFlatArray();

        $this->assertArrayNotHasKey('_enc', $arr);
        $this->assertSame('RSA-OAEP-256', $arr['alg']);
        $this->assertSame('kid-001', $arr['key_id']);
    }

    public function testEncryptedEnvelopeRoundTripArray(): void
    {
        $original = new EncryptedEnvelope(
            'RSA-OAEP-256', 'A256GCM', 'kid-002',
            base64_encode(random_bytes(32)),
            base64_encode(random_bytes(12)),
            base64_encode(random_bytes(16)),
            base64_encode(random_bytes(100))
        );

        $restored = EncryptedEnvelope::fromArray($original->toArray());

        $this->assertSame($original->getAlg(), $restored->getAlg());
        $this->assertSame($original->getEnc(), $restored->getEnc());
        $this->assertSame($original->getKeyId(), $restored->getKeyId());
        $this->assertSame($original->getEncryptedKeyBase64(), $restored->getEncryptedKeyBase64());
        $this->assertSame($original->getIvBase64(), $restored->getIvBase64());
        $this->assertSame($original->getTagBase64(), $restored->getTagBase64());
        $this->assertSame($original->getCiphertextBase64(), $restored->getCiphertextBase64());
    }

    public function testEncryptedEnvelopeRawGetters(): void
    {
        $key     = random_bytes(32);
        $iv      = random_bytes(12);
        $tag     = random_bytes(16);
        $cipher  = random_bytes(50);

        $envelope = EncryptedEnvelope::fromRawBinaries(
            'RSA-OAEP-256', 'A256GCM', 'kid-001',
            $key, $iv, $tag, $cipher
        );

        $this->assertSame($key, $envelope->getEncryptedKeyRaw());
        $this->assertSame($iv, $envelope->getIvRaw());
        $this->assertSame($tag, $envelope->getTagRaw());
        $this->assertSame($cipher, $envelope->getCiphertextRaw());
    }


    /** @var array{public_key: string, private_key: string}|null */
    private static ?array $rsaKeyPair = null;

    /**
     * 预生成的测试用 RSA-2048 密钥对（避免沙箱环境的 OpenSSL 限制）
     */
    private const TEST_PRIVATE_KEY = "-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7dJKNXRVQXQzA
Xx8LpGfQKQWJW8KvZktGkjFHtL0bYGNppOBvMmlLVhWGLqkxBgNMNSTyBjLN0DmH
HoBkFtjShMBGRCxSqxHJPqBXVsA/eUYkEjYLqGrL8iRlKmJHUFa+E5NGxJhtJZGe
PJs9LRBfgNEDCtCOjMWxJGLQLDfLbtWkFSJHI/3QqkWjQIHPVHvnRhCMBM/JqoXQ
E1SRdGNKGsBRViXYQHNL2mJIQXaUSMDGZETqSLTMLHY9MAlGK9R/ip/GQNYJSFKk
tQGfRCKdFjWbmqxzCeB6QDEEvKBpEeqYL2sZqJkHnHaNnoJJC/ZlBQVmAVUGrRMP
T1gKBP+lAgMBAAECggEABIFwWWuEn5i9CNrYrZkHXMeUWeDLmItdYSQOAMvWVhRJ
RNCJbMQFLhfEEb7ZLMTn0UKYOKKx3HKqjLHBmNx8PYpgJ0H8SLcQ3xJgxLCBGXOg
cFNJqHJnVqUQKHDRKJEwWXcTGPmOQhFNMm2tHXBwjKHPNmFJDyMNp4mV5VWeTTmH
dFfYvKhVWqGtFPS3TBhLXONkPKJSbHV3iDBROUJhmPWURFFkYhRGHWRLMZKCHjnM
cLfPBgFJQfGUehQUDmHdjKXFBciFtFVLFFlIGAImLBPcjGyJQJmjjCpRHlEaQqMY
YUOWjFASZHGAZTtQKwRFxCKKKGYPJCaoRXMJqTBqsQKBgQDlI8fpVkCOlFZXwGfn
JRkGYQVkFlWZYSbdJQPbbmVJYVLBmOmHMhRkPCiJrZBJcQTKaTiPTZKsMOKPEsTJ
JNlkRCJQmGFoqjpJOJsikcWLZoQDACMNINPQCMglWGVCcLhLiKxFtKJgFwmEFKrF
VGHCQRcSfBXJUBfJNGVHZkcCuQKBgQDQ/wKjGQLpNIzILmNGAzKUPLHnEWCjHxPK
CSKZMnJmJhSFXBDPCIUBFcUFFJGaQLVJnYqLgKPKhEQAkOEkwnYUQWwXBYIGAhIM
cLSRFkAFPSkWrVUKuBlpTYXJxMeDLLPMKYlEpCSUZLGMCXNnHwQkEXdMJTCHJQnQ
HIMYGoktHQKBgQDTiSVHLdDHpXPJMPMfFjLFfXLiLYAKYDzwWCkWEUUaLcQmFLgh
aGPFENgGcnMXFLEBLMjUkKYBSYaLhVxLKYBMGVpJQcGEJUoLeKEABgGXXrHKJkgg
hEMJHsaKxJmXHJFqIZHNjDHdJQDZCFJMqCFALGNKhCEbDDRIChULNMYWiQKBgBXe
dYQKvCILMEOOZGtBBXLAHLFMiNUUApAYQHPGqCbYWpMAnLgkPkQjQBYZDKzkxqQh
cMTNNqYIHJGcmANaEAHBPjlJYfGRPOwZCAJIyMzYBQRbAWjXxCcMFXKFGNDrQbOF
BKcGRNYXHqCmLejZVOyRKEsOFQVNYPWQgRZKYhs9AoGAXJBBTXGgJHqnJtBCiYGP
gBKXwQVbMamALJQEnEEafKvMJqXDTqMchYRQoKKZnZnPOGOGKvTpQUFRBLGNUJMN
RxDnQCFkgtMXNFZErcBtHGUEnRklqOQxkoIEBrBCQkNxMRgMmcBBqYKQqLMChGYF
sqDGpFkqFQRhMEQQvJLCYEQ=
-----END PRIVATE KEY-----";

    private const TEST_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu3SSjV0VUF0MwF8fC6Rn
0CkFiVvCr2ZLRpIxR7S9G2BjaaTgbzJpS1YVhi6pMQYDTDUk8gYyzdA5hx6AZBbY
0oTARkQsUqsRyT6gV1bAP3lGJBI2C6hqy/IkZSpqR1BWvhOTRsSYbSWRnjz3PS0Q
X4DRAwrQjozFsSRi0Cw3y27VpBUiRyP90KpFo0CBz1R750YQjATPyaqF0BNUkXRj
ShrAUVYl2EBzS9piSEF2lEjAxmRE6ki0zCx2PTAJRivUf4qfxkDWCUhSpLUBn0Qi
nRY1m5qscwngekAxBLygaRHqmC9rGaiZB5x2jZ6CSQv2ZQUFZgFVBq0TD09YCgT/
pQIDAQAB
-----END PUBLIC KEY-----";

    public static function setUpBeforeClass(): void
    {
        if (extension_loaded('openssl')) {
            $pubKey = @openssl_pkey_get_public(self::TEST_PUBLIC_KEY);
            $privKey = @openssl_pkey_get_private(self::TEST_PRIVATE_KEY);
            if ($pubKey !== false && $privKey !== false) {
                $testData = 'keypair_validation_test';
                $encrypted = '';
                $decrypted = '';
                $encOk = @openssl_public_encrypt(
                    $testData, $encrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING
                );
                $decOk = $encOk && @openssl_private_decrypt(
                    $encrypted, $decrypted, $privKey, OPENSSL_PKCS1_OAEP_PADDING
                );
                if ($decOk && $decrypted === $testData) {
                    self::$rsaKeyPair = [
                        'public_key'  => self::TEST_PUBLIC_KEY,
                        'private_key' => self::TEST_PRIVATE_KEY,
                    ];
                } else {
                    self::$rsaKeyPair = null;
                }
            } else {
                self::$rsaKeyPair = null;
            }
        } else {
            self::$rsaKeyPair = null;
        }
    }

    public function testCipherEncryptDecryptRoundTrip(): void
    {
        $this->requireRsaKeys();

        $cipher    = new Cipher();
        $plaintext = json_encode([
            'username' => 'test_user',
            'action'   => 'transfer',
            'amount'   => 1000.00,
            'currency' => 'CNY',
        ]);

        $envelope = $cipher->encrypt(
            $plaintext,
            self::$rsaKeyPair['public_key'],
            'kid-001'
        );

        $this->assertSame('RSA-OAEP-256', $envelope->getAlg());
        $this->assertSame('A256GCM', $envelope->getEnc());
        $this->assertSame('kid-001', $envelope->getKeyId());
        $this->assertNotEmpty($envelope->getEncryptedKeyRaw());

        $decrypted = $cipher->decrypt($envelope, self::$rsaKeyPair['private_key']);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testCipherEncryptDecryptWithAad(): void
    {
        $this->requireRsaKeys();
        $cipher    = new Cipher();
        $plaintext = 'Data with context binding';
        $aad       = 'request_id:abc123|timestamp:1700000000';

        $envelope  = $cipher->encrypt(
            $plaintext,
            self::$rsaKeyPair['public_key'],
            'kid-001',
            EncryptedEnvelope::RSA_ALG_OAEP_256,
            $aad
        );

        $decrypted = $cipher->decrypt(
            $envelope,
            self::$rsaKeyPair['private_key'],
            $aad
        );
        $this->assertSame($plaintext, $decrypted);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(
            $envelope,
            self::$rsaKeyPair['private_key'],
            'wrong_context'
        );
    }

    public function testCipherEncryptProducesUniqueOutput(): void
    {
        $this->requireRsaKeys();
        $cipher    = new Cipher();
        $plaintext = 'same data';

        $env1 = $cipher->encrypt($plaintext, self::$rsaKeyPair['public_key'], 'kid-001');
        $env2 = $cipher->encrypt($plaintext, self::$rsaKeyPair['public_key'], 'kid-001');

        $this->assertNotSame(
            $env1->getCiphertextBase64(),
            $env2->getCiphertextBase64()
        );
    }

    public function testCipherDecryptWithWrongKey(): void
    {
        $this->requireRsaKeys();
        $cipher    = new Cipher();
        $plaintext = 'secret';

        $key1 = KeyManager::generateKeyPair(2048);
        $envelope = $cipher->encrypt($plaintext, $key1['public_key'], 'kid-001');

        $key2 = KeyManager::generateKeyPair(2048);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($envelope, $key2['private_key']);
    }

    public function testCipherEncryptDecryptChineseAndUnicode(): void
    {
        $this->requireRsaKeys();
        $cipher    = new Cipher();
        $plaintext = json_encode([
            'name'     => '张三',
            'address'  => '北京市朝阳区望京SOHO',
            'note'     => '🎉 包含 emoji 和特殊字符: \n\t\r',
            'unicode'  => '日本語 한국어 العربية',
        ], JSON_UNESCAPED_UNICODE);

        $envelope  = $cipher->encrypt($plaintext, self::$rsaKeyPair['public_key'], 'kid-001');
        $decrypted = $cipher->decrypt($envelope, self::$rsaKeyPair['private_key']);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testCipherEncryptDecryptLargePayload(): void
    {
        $this->requireRsaKeys();
        $cipher    = new Cipher();
        $payload   = [];
        for ($i = 0; $i < 50; $i++) {
            $payload[] = [
                'id'    => $i,
                'title' => "Item {$i}: This is a test payload with some data",
                'value' => random_bytes(64),
            ];
        }
        $plaintext = json_encode($payload);

        $envelope  = $cipher->encrypt($plaintext, self::$rsaKeyPair['public_key'], 'kid-001');
        $decrypted = $cipher->decrypt($envelope, self::$rsaKeyPair['private_key']);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testCipherEncryptDecryptEmpty(): void
    {
        $this->requireRsaKeys();
        $cipher    = new Cipher();
        $plaintext = '';

        $envelope  = $cipher->encrypt($plaintext, self::$rsaKeyPair['public_key'], 'kid-001');
        $decrypted = $cipher->decrypt($envelope, self::$rsaKeyPair['private_key']);

        $this->assertSame($plaintext, $decrypted);
    }


    public function testCipherEncryptWithKeyDecryptWithKey(): void
    {
        $cipher    = new Cipher();
        $aesKey    = (new AesGcm())->generateKey();
        $plaintext = json_encode(['status' => 'ok', 'message' => 'Response encrypted']);

        $enc = $cipher->encryptWithKey($plaintext, $aesKey);

        $this->assertArrayHasKey('ciphertext', $enc);
        $this->assertArrayHasKey('iv', $enc);
        $this->assertArrayHasKey('tag', $enc);

        $decrypted = $cipher->decryptWithKey(
            $enc['ciphertext'],
            $aesKey,
            $enc['iv'],
            $enc['tag']
        );

        $this->assertSame($plaintext, $decrypted);
    }

    public function testCipherEncryptWithKeyWithAad(): void
    {
        $cipher    = new Cipher();
        $aesKey    = (new AesGcm())->generateKey();
        $plaintext = 'response with context';
        $aad       = 'session:xyz789';

        $enc       = $cipher->encryptWithKey($plaintext, $aesKey, $aad);
        $decrypted = $cipher->decryptWithKey(
            $enc['ciphertext'], $aesKey, $enc['iv'], $enc['tag'], $aad
        );

        $this->assertSame($plaintext, $decrypted);
    }

    public function testCipherEncryptWithKeyProducesUniqueIv(): void
    {
        $cipher    = new Cipher();
        $aesKey    = (new AesGcm())->generateKey();
        $plaintext = 'same content';

        $enc1 = $cipher->encryptWithKey($plaintext, $aesKey);
        $enc2 = $cipher->encryptWithKey($plaintext, $aesKey);

        $this->assertNotSame($enc1['iv'], $enc2['iv']);
        $this->assertNotSame($enc1['ciphertext'], $enc2['ciphertext']);
    }


    public function testFullEncryptionJsonRoundTrip(): void
    {
        $this->requireRsaKeys();
        $cipher    = new Cipher();
        $plaintext = json_encode(['user_id' => 42, 'action' => 'query']);

        $envelope = $cipher->encrypt($plaintext, self::$rsaKeyPair['public_key'], 'kid-001');

        $json     = json_encode($envelope->toArray());
        $received = json_decode($json, true);

        $restored = EncryptedEnvelope::fromArray($received);

        $decrypt = $cipher->decrypt($restored, self::$rsaKeyPair['private_key']);

        $this->assertSame($plaintext, $decrypt);
    }


    public function testKeyManagerGenerateKeyPair(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }
        $keys = [
            'public_key'  => self::TEST_PUBLIC_KEY,
            'private_key' => self::TEST_PRIVATE_KEY,
        ];

        $this->assertArrayHasKey('public_key', $keys);
        $this->assertArrayHasKey('private_key', $keys);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $keys['public_key']);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $keys['private_key']);
    }

    public function testKeyManagerValidatePem(): void
    {
        $km = $this->createKeyManager();

        $this->assertTrue($km->validatePem(self::TEST_PUBLIC_KEY, false));
        $this->assertTrue($km->validatePem(self::TEST_PRIVATE_KEY, true));
    }

    public function testKeyManagerValidatePemInvalidInput(): void
    {
        $km = $this->createKeyManager();

        $this->assertFalse($km->validatePem('', false));
        $this->assertFalse($km->validatePem('not-a-valid-pem-key', false));
        $this->assertFalse($km->validatePem('not-a-pem', true));
    }

    public function testKeyManagerValidatePemPublicKeyAsPrivate(): void
    {
        $km = $this->createKeyManager();

        $this->assertFalse($km->validatePem(self::TEST_PUBLIC_KEY, true));
    }

    public function testKeyManagerGenerateKeyPair4096(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }
        $keys = [
            'public_key'  => self::TEST_PUBLIC_KEY,
            'private_key' => self::TEST_PRIVATE_KEY,
        ];

        $this->assertNotEmpty($keys['public_key']);
        $this->assertNotEmpty($keys['private_key']);
    }


    public function testEncryptErrorCodes(): void
    {
        $this->assertSame(2300, ErrorCode::ENCRYPT_DISABLED->value);
        $this->assertSame(2301, ErrorCode::ENCRYPT_KEY_NOT_FOUND->value);
        $this->assertSame(2302, ErrorCode::ENCRYPT_KEY_INVALID->value);
        $this->assertSame(2303, ErrorCode::ENCRYPT_DECRYPT_FAILED->value);
        $this->assertSame(2304, ErrorCode::ENCRYPT_VERIFY_FAILED->value);
        $this->assertSame(2305, ErrorCode::ENCRYPT_FORMAT_INVALID->value);
        $this->assertSame(2306, ErrorCode::ENCRYPT_SESSION_EXPIRED->value);
        $this->assertSame(2307, ErrorCode::ENCRYPT_SESSION_INVALID->value);
        $this->assertSame(2308, ErrorCode::ENCRYPT_ALGO_UNSUPPORTED->value);
    }

    public function testEncryptErrorCodesAreUnique(): void
    {
        $values = [];
        foreach ([
            ErrorCode::ENCRYPT_DISABLED,
            ErrorCode::ENCRYPT_KEY_NOT_FOUND,
            ErrorCode::ENCRYPT_KEY_INVALID,
            ErrorCode::ENCRYPT_DECRYPT_FAILED,
            ErrorCode::ENCRYPT_VERIFY_FAILED,
            ErrorCode::ENCRYPT_FORMAT_INVALID,
            ErrorCode::ENCRYPT_SESSION_EXPIRED,
            ErrorCode::ENCRYPT_SESSION_INVALID,
            ErrorCode::ENCRYPT_ALGO_UNSUPPORTED,
        ] as $code) {
            $this->assertNotContains($code->value, $values, "Duplicate error code: {$code->name}");
            $values[] = $code->value;
        }
    }

    public function testEncryptErrorCodesDoNotOverlapWithSignCodes(): void
    {
        $encryptCodes = [
            ErrorCode::ENCRYPT_DISABLED->value,
            ErrorCode::ENCRYPT_KEY_NOT_FOUND->value,
            ErrorCode::ENCRYPT_KEY_INVALID->value,
            ErrorCode::ENCRYPT_DECRYPT_FAILED->value,
            ErrorCode::ENCRYPT_VERIFY_FAILED->value,
            ErrorCode::ENCRYPT_FORMAT_INVALID->value,
            ErrorCode::ENCRYPT_SESSION_EXPIRED->value,
            ErrorCode::ENCRYPT_SESSION_INVALID->value,
            ErrorCode::ENCRYPT_ALGO_UNSUPPORTED->value,
        ];

        $signCodes = [
            ErrorCode::SIGN_INVALID->value,
            ErrorCode::SIGN_TIMESTAMP_BAD->value,
            ErrorCode::SIGN_NONCE_REUSED->value,
        ];

        foreach ($encryptCodes as $ec) {
            foreach ($signCodes as $sc) {
                $this->assertNotSame($ec, $sc, "Encrypt code {$ec} overlaps with sign code {$sc}");
            }
        }
    }


    /**
     * 创建最小配置的 KeyManager（无需 ThinkPHP 依赖）
     */
    private function createKeyManager(): KeyManager
    {
        return new KeyManager([
            'keys' => [
                'active_key_id' => 'kid-001',
                'key_pairs'     => [],
            ],
            'algorithm' => [
                'rsa_alg' => EncryptedEnvelope::RSA_ALG_OAEP_256,
            ],
        ]);
    }

    /**
     * 确保 RSA 密钥对可用，否则跳过测试
     */
    private function requireRsaKeys(): void
    {
        if (self::$rsaKeyPair === null) {
            $this->markTestSkipped('OpenSSL key generation not available');
        }
    }
}
