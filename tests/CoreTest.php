<?php
declare(strict_types=1);

namespace wise\api\test;

use PHPUnit\Framework\TestCase;
use wise\api\exception\JwtException;
use wise\api\jwt\HS256Signer;
use wise\api\jwt\Jwt;
use wise\api\response\ErrorCode;
use wise\api\sign\SignVerifier;

/**
 * 核心组件 PHPUnit 测试
 */
class CoreTest extends TestCase
{
    public function testErrorCodeEnum(): void
    {
        $this->assertSame(0,    ErrorCode::SUCCESS->value);
        $this->assertSame(2001, ErrorCode::TOKEN_INVALID->value);
        $this->assertSame(3000, ErrorCode::RATE_LIMITED->value);
        $this->assertSame(2100, ErrorCode::SIGN_INVALID->value);
    }

    public function testJwtIssueAndParse(): void
    {
        $signer = new HS256Signer('unit-test-secret-must-be-long-enough-32+');
        $jwt    = new Jwt('HS256', $signer, 'unit-test', 60, 30);

        $token = $jwt->issue(42, ['role' => 'user']);
        $this->assertIsString($token);
        $this->assertSame(2, substr_count($token, '.'));

        $payload = $jwt->parse($token);
        $this->assertSame('42',    $payload['sub']);
        $this->assertSame('user',  $payload['role']);
        $this->assertSame('unit-test', $payload['iss']);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('jti', $payload);
    }

    public function testJwtTamperedSignatureRejected(): void
    {
        $signer = new HS256Signer('unit-test-secret-must-be-long-enough-32+');
        $jwt    = new Jwt('HS256', $signer, 'unit-test', 60, 30);
        $token  = $jwt->issue(1);
        $parts  = explode('.', $token);
        $parts[2] = 'tampered';
        $bad     = implode('.', $parts);

        $this->expectException(JwtException::class);
        $jwt->parse($bad);
    }

    public function testJwtTryParse(): void
    {
        $signer = new HS256Signer('unit-test-secret-must-be-long-enough-32+');
        $jwt    = new Jwt('HS256', $signer, 'unit-test', 60, 30);

        $this->assertNotNull($jwt->tryParse($jwt->issue(1)));
        $this->assertNull($jwt->tryParse('not-a-jwt'));
        $this->assertNull($jwt->tryParse('a.b.c'));
    }

    public function testJwtTtlAndShouldRefresh(): void
    {
        $signer = new HS256Signer('unit-test-secret-must-be-long-enough-32+');
        $jwt    = new Jwt('HS256', $signer, 'unit-test', 60, 30);

        $payload = $jwt->parse($jwt->issue(1));
        $this->assertGreaterThan(0, $jwt->ttlOf($payload));
        $this->assertFalse($jwt->shouldRefresh($payload));
    }

    public function testBase64UrlRoundTrip(): void
    {
        $original = 'hello/world?subject+_';
        $encoded  = HS256Signer::base64UrlEncode($original);
        $decoded  = HS256Signer::base64UrlDecode($encoded);
        $this->assertSame($original, $decoded);
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
    }

    public function testSignVerifierSignAndVerify(): void
    {
        $verifier = new SignVerifier();
        $params   = [
            'app_key'   => 'demo',
            'timestamp' => 1700000000,
            'nonce'     => 'abc',
            'amount'    => 10.5,
            'tags'      => ['x', 'y'],
        ];
        $secret   = 'secret-key';
        $sign     = $verifier->sign($params, $secret);

        $this->assertSame(64, strlen($sign));
        $this->assertTrue($verifier->verify(array_merge($params, ['sign' => $sign]), $secret)['ok']);

        // 修改 amount 后应失败
        $bad = $verifier->verify(array_merge($params, ['sign' => $sign, 'amount' => 999]), $secret);
        $this->assertFalse($bad['ok']);
    }

    public function testSignVerifierBuildStringSortsKeys(): void
    {
        $verifier = new SignVerifier();
        $a = $verifier->buildSignString(['z' => '1', 'a' => '2', 'm' => '3']);
        $b = $verifier->buildSignString(['a' => '2', 'm' => '3', 'z' => '1']);
        $this->assertSame($a, $b);
        $this->assertSame('a=2&m=3&z=1', $a);
    }

    public function testSignVerifierArrayValueIsJsonEncoded(): void
    {
        $verifier = new SignVerifier();
        $s = $verifier->buildSignString(['tags' => ['a', 'b']]);
        $this->assertStringContainsString('tags=["a","b"]', $s);
    }
}
