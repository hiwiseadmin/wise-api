<?php
/**
 * 独立功能测试（不依赖 ThinkPHP 容器）
 * 验证 SignVerifier、ErrorCode 等核心逻辑
 *
 * 注意：JWT 功能已完全移交 wise-jwt 包，详见 vendor/wise/jwt
 */

declare(strict_types=1);

$base = __DIR__ . '/../src';
require $base . '/sign/SignVerifier.php';
require $base . '/response/ErrorCode.php';

use wise\api\response\ErrorCode;
use wise\api\sign\SignVerifier;

echo "==========================================\n";
echo "  wise-api 包核心功能测试\n";
echo "==========================================\n\n";

// -----------------------------
// 1. ErrorCode 枚举
// -----------------------------
echo "[1] ErrorCode 枚举值校验\n";
assert(ErrorCode::SUCCESS->value === 0);
assert(ErrorCode::RATE_LIMITED->value === 3000);
assert(ErrorCode::TOKEN_INVALID->value === 2001);
assert(ErrorCode::SIGN_INVALID->value === 2100);
assert(ErrorCode::CIRCUIT_OPEN->value === 9001);
echo "    ✓ 业务码 0/2001/2100/3000/9001 正确\n";
echo "    ✓ 共 " . count(ErrorCode::cases()) . " 个错误码定义\n\n";

// -----------------------------
// 2. SignVerifier 签名验证
// -----------------------------
echo "[2] SignVerifier HMAC-SHA256 签名\n";
$verifier = new SignVerifier();
$params = [
    'app_key'   => 'demo',
    'timestamp' => time(),
    'nonce'     => 'xyz123',
    'user_id'   => 100,
    'amount'    => 99.5,
    'tags'      => ['a', 'b', 'c'],
];
$secret = 'app-secret-key';
$sign = $verifier->sign($params, $secret);
assert(strlen($sign) === 64);
echo "    ✓ 签名生成：{$sign}\n";

$check = $verifier->verify(array_merge($params, ['sign' => $sign]), $secret);
assert($check['ok'] === true, 'verify should pass');
echo "    ✓ 签名校验通过\n";

// 修改参数应失败
$bad = $verifier->verify(array_merge($params, ['sign' => $sign, 'amount' => 999]), $secret);
assert($bad['ok'] === false);
echo "    ✓ 篡改金额后签名失败：{$bad['reason']}\n\n";

// -----------------------------
// 3. SignVerifier 字符串构建
// -----------------------------
echo "[3] SignVerifier 签名字符串构建\n";
$a = $verifier->buildSignString(['z' => '1', 'a' => '2', 'm' => '3']);
$b = $verifier->buildSignString(['a' => '2', 'm' => '3', 'z' => '1']);
assert($a === $b, 'key order should not affect sign string');
assert($a === 'a=2&m=3&z=1');
echo "    ✓ 键排序一致：{$a}\n";

$s = $verifier->buildSignString(['tags' => ['a', 'b']]);
assert(str_contains($s, 'tags=["a","b"]'));
echo "    ✓ 数组值 JSON 编码：{$s}\n\n";

// -----------------------------
// 4. SignVerifier key prefix
// -----------------------------
echo "[4] SignVerifier key prefix 设置\n";
$verifier2 = new SignVerifier();
$verifier2->setKeyPrefix('wise_api:test:sign');
$s2 = $verifier2->buildSignString(['x' => '1']);
assert($s2 === 'x=1');
echo "    ✓ key prefix 设置后功能正常\n\n";

echo "==========================================\n";
echo "  ✓ 所有核心功能测试通过\n";
echo "==========================================\n";
