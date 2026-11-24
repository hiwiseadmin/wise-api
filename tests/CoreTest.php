<?php
declare(strict_types=1);

namespace wise\api\test;

use PHPUnit\Framework\TestCase;
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


    public function testErrorCodeHttpMappingCompleteness(): void
    {
        $wrapper = new \wise\api\response\ResponseWrapper();

        $this->assertSame(401, $wrapper->httpCodeFor(ErrorCode::TOKEN_INVALID));
        $this->assertSame(401, $wrapper->httpCodeFor(ErrorCode::TOKEN_EXPIRED));
        $this->assertSame(401, $wrapper->httpCodeFor(ErrorCode::UNAUTHORIZED));
        $this->assertSame(401, $wrapper->httpCodeFor(ErrorCode::REFRESH_TOKEN_INVALID));
        $this->assertSame(401, $wrapper->httpCodeFor(ErrorCode::REFRESH_TOKEN_EXPIRED));
        $this->assertSame(401, $wrapper->httpCodeFor(ErrorCode::REFRESH_TOKEN_REVOKED));

        $this->assertSame(403, $wrapper->httpCodeFor(ErrorCode::FORBIDDEN));
        $this->assertSame(403, $wrapper->httpCodeFor(ErrorCode::IP_BLOCKED));

        $this->assertSame(429, $wrapper->httpCodeFor(ErrorCode::RATE_LIMITED));

        $this->assertSame(503, $wrapper->httpCodeFor(ErrorCode::CIRCUIT_OPEN));

        $this->assertSame(502, $wrapper->httpCodeFor(ErrorCode::WEBHOOK_FAILED));

        $this->assertSame(200, $wrapper->httpCodeFor(ErrorCode::VALIDATE_FAILED));
        $this->assertSame(200, $wrapper->httpCodeFor(ErrorCode::DATA_NOT_FOUND));

        $this->assertSame(200, $wrapper->httpCodeFor(ErrorCode::INVALID_PARAM));
        $this->assertSame(200, $wrapper->httpCodeFor(ErrorCode::MISSING_PARAM));

        $this->assertSame(500, $wrapper->httpCodeFor(ErrorCode::UNKNOWN_ERROR));
        $this->assertSame(500, $wrapper->httpCodeFor(ErrorCode::SERVER_ERROR));
        $this->assertSame(500, $wrapper->httpCodeFor(ErrorCode::UPSTREAM_ERROR));

        $this->assertSame(500, $wrapper->httpCodeFor(9999));
        $this->assertSame(200, $wrapper->httpCodeFor(50));
    }

    public function testErrorCodeAllCasesHaveHttpMapping(): void
    {
        $wrapper = new \wise\api\response\ResponseWrapper();
        $missing = [];

        foreach (ErrorCode::cases() as $case) {
            $httpCode = $wrapper->httpCodeFor($case->value);
            if ($httpCode < 200 || $httpCode > 599) {
                $missing[] = $case->name . '(' . $case->value . ') → ' . $httpCode;
            }
        }

        $this->assertEmpty($missing, '以下 ErrorCode 缺少有效的 HTTP 状态码映射: ' . implode(', ', $missing));
    }

    public function testResponseWrapperErrorMsgFirst(): void
    {
        $wrapper = new \wise\api\response\ResponseWrapper();
        $result  = $wrapper->error('参数错误', ['field' => 'name'], ErrorCode::INVALID_PARAM);

        $data = json_decode($result->getContent(), true);
        $this->assertSame(ErrorCode::INVALID_PARAM->value, $data['code']);
        $this->assertSame('参数错误', $data['msg']);
        $this->assertSame(['field' => 'name'], $data['data']);
        $this->assertSame(200, $result->getCode());
    }

    public function testResponseWrapperSuccessTimestamp(): void
    {
        $wrapper = new \wise\api\response\ResponseWrapper();
        $result  = $wrapper->success('ok', ['id' => 1]);

        $data = json_decode($result->getContent(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame('ok', $data['msg']);
        $this->assertSame(['id' => 1], $data['data']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testSignVerifierKeyPrefix(): void
    {
        $verifier = new SignVerifier();
        $verifier->setKeyPrefix('wise_api:test:sign');

        $s = $verifier->buildSignString(['a' => '1', 'b' => '2']);
        $this->assertSame('a=1&b=2', $s);
    }

    public function testCircuitBreakerStateTransitions(): void
    {
        $mockCache = new class {
            private array $store = [];
            public function get(string $key, $default = null) {
                return $this->store[$key] ?? $default;
            }
            public function set(string $key, $value, $ttl = null): void {
                $this->store[$key] = $value;
            }
        };

        $cb = new \wise\support\circuit\CircuitBreaker('test_service', $mockCache, [
            'failure_threshold' => 2,
            'timeout'           => 1,
            'success_threshold' => 2,
        ]);

        $this->assertTrue($cb->isAvailable());
        $this->assertSame('closed', $cb->getState());

        $cb->reportFailure();
        $this->assertTrue($cb->isAvailable());
        $this->assertSame(1, $cb->getFailureCount());

        $cb->reportFailure();
        $this->assertFalse($cb->isAvailable());
        $this->assertSame('open', $cb->getState());

        $cb->reset();
        $this->assertTrue($cb->isAvailable());
        $this->assertSame('closed', $cb->getState());
    }

    public function testRateLimiterGroupMatching(): void
    {
        $limiter = new \wise\api\rate\RateLimitCoordinator(
            new \wise\api\rate\TokenBucket(),
            new \wise\api\rate\SlidingWindow(),
            [
                'enable' => true,
                'algorithm' => 'token_bucket',
                'default' => ['capacity' => 60, 'rate' => 1.0, 'algorithm' => 'token_bucket'],
                'groups' => [
                    '/attachment/upload' => ['capacity' => 5, 'rate' => 0.5, 'algorithm' => 'token_bucket'],
                ],
            ]
        );

        $this->assertTrue(true);
    }
}
