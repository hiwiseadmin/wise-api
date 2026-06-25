<?php
/**
 * 独立功能测试（不依赖 ThinkPHP 容器）
 * 验证 HS256、RS256、SignVerifier、错误码等核心逻辑
 */

declare(strict_types=1);

// 直接引入必要的类
$base = __DIR__ . '/../src';
require $base . '/exception/JwtException.php';
require $base . '/jwt/SignerInterface.php';
require $base . '/jwt/HS256Signer.php';
require $base . '/jwt/RS256Signer.php';
require $base . '/jwt/Jwt.php';
require $base . '/sign/SignVerifier.php';
require $base . '/response/ErrorCode.php';

use wise\api\exception\JwtException;
use wise\api\jwt\HS256Signer;
use wise\api\jwt\Jwt;
use wise\api\jwt\RS256Signer;
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
echo "    ✓ 业务码 0/2001/3000 正确\n\n";

// -----------------------------
// 2. HS256 JWT 签发与解析
// -----------------------------
echo "[2] HS256 JWT 签发/解析/续签\n";
$signer = new HS256Signer('test-secret-must-be-long-enough-for-hs256');
$jwt    = new Jwt('HS256', $signer, 'test-app', 7200, 1800);

$token = $jwt->issue(12345, ['role' => 'admin']);
assert(is_string($token) && substr_count($token, '.') === 2);
echo "    ✓ 签发成功：{$token}\n";

$payload = $jwt->parse($token);
assert($payload['sub'] === '12345');
assert($payload['role'] === 'admin');
assert($payload['iss'] === 'test-app');
assert(isset($payload['exp']));
echo "    ✓ 解析成功，sub={$payload['sub']}, role={$payload['role']}\n";
echo "    ✓ ttl=" . $jwt->ttlOf($payload) . "s, shouldRefresh=" . var_export($jwt->shouldRefresh($payload), true) . "\n";

// 安全解析
$ok = $jwt->tryParse($token);
assert($ok !== null);
echo "    ✓ tryParse 返回非 null\n";

// 无效 token
$bad = $jwt->tryParse('invalid.token.here');
assert($bad === null);
echo "    ✓ 非法 token 返回 null\n\n";

// -----------------------------
// 3. 篡改 token 后应失败
// -----------------------------
echo "[3] 篡改 token 验签失败\n";
$parts = explode('.', $token);
$tampered = $parts[0] . '.' . $parts[1] . '.invalidsig';
$threw = false;
try {
    $jwt->parse($tampered);
} catch (JwtException $e) {
    $threw = true;
    echo "    ✓ 抛出异常：{$e->getMessage()}\n";
}
assert($threw);
echo "\n";

// -----------------------------
// 4. SignVerifier 签名验证
// -----------------------------
echo "[4] SignVerifier HMAC-SHA256 签名\n";
$verifier = new SignVerifier();
$params = [
    'app_key'   => 'demo',
    'timestamp' => time(),
    'nonce'     => 'xyz123',
    'user_id'   => 100,
    'amount'    => 99.5,
    'tags'      => ['a', 'b', 'c'], // 数组会 json_encode
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
// 5. RS256 桩测试（无 PEM 文件时不应崩溃）
// -----------------------------
echo "[5] RS256 桩测试\n";
try {
    $rs = new RS256Signer('', '');
    echo "    ✓ 空密钥构造 RS256 不报错（未配置状态）\n";
} catch (\Throwable $e) {
    echo "    ⚠ RS256 构造异常：{$e->getMessage()}\n";
}
echo "\n";

// -----------------------------
// 6. base64url 编解码
// -----------------------------
echo "[6] base64url 编解码\n";
$original = 'subject?_#';
$enc = HS256Signer::base64UrlEncode($original);
$dec = HS256Signer::base64UrlDecode($enc);
assert($dec === $original);
echo "    ✓ '{$original}' ↔ '{$enc}' ↔ '{$dec}'\n\n";

// -----------------------------
// 7. HS256 短密钥异常测试
// -----------------------------
echo "[7] HS256 短密钥异常测试\n";
$shortKeyThrew = false;
try {
    new HS256Signer('short');
} catch (JwtException $e) {
    $shortKeyThrew = true;
    echo "    ✓ <16 字节密钥抛出异常：{$e->getMessage()}\n";
}
assert($shortKeyThrew, 'HS256Signer should throw for keys < 16 bytes');

// 16-31 字节密钥应触发 WARNING 但不抛异常
$warningTriggered = false;
set_error_handler(function ($errno) use (&$warningTriggered) {
    if ($errno === E_USER_WARNING) {
        $warningTriggered = true;
    }
    return true;
});
$mediumSigner = new HS256Signer('exactly-16-bytes!!');
restore_error_handler();
assert($warningTriggered, 'HS256Signer should trigger E_USER_WARNING for keys < 32 bytes');
echo "    ✓ 16-31 字节密钥触发 E_USER_WARNING\n";

// 32+ 字节密钥不应抛异常也不应触发 WARNING
$warningTriggered2 = false;
set_error_handler(function ($errno) use (&$warningTriggered2) {
    if ($errno === E_USER_WARNING) {
        $warningTriggered2 = true;
    }
    return true;
});
$longSigner = new HS256Signer('this-is-a-very-long-secret-key-32bytes!');
restore_error_handler();
assert(!$warningTriggered2, 'HS256Signer should not trigger warning for keys >= 32 bytes');
echo "    ✓ 32+ 字节密钥无异常无警告\n\n";

// -----------------------------
// 8. JWT audience 声明测试
// -----------------------------
echo "[8] JWT audience 声明测试\n";
$jwtWithAud = new Jwt('HS256', $signer, 'test-app', 7200, 1800, 'my-audience');
$tokenWithAud = $jwtWithAud->issue(12345, ['role' => 'admin']);
$payloadWithAud = $jwtWithAud->parse($tokenWithAud);
assert($payloadWithAud['aud'] === 'my-audience', 'aud claim should match');
echo "    ✓ audience 声明正确：aud={$payloadWithAud['aud']}\n";

// 无 audience 时不设置 aud
$jwtNoAud = new Jwt('HS256', $signer, 'test-app', 7200, 1800);
$tokenNoAud = $jwtNoAud->issue(12345);
$payloadNoAud = $jwtNoAud->parse($tokenNoAud);
assert(!isset($payloadNoAud['aud']), 'aud claim should not exist when audience is null');
echo "    ✓ 未设置 audience 时无 aud 声明\n\n";

// -----------------------------
// 9. JWT 续签时自定义 claims 保留测试
// -----------------------------
echo "[9] JWT 续签时自定义 claims 保留\n";
$signer9 = new HS256Signer('test-secret-must-be-long-enough-for-hs256');
$jwt9 = new Jwt('HS256', $signer9, 'test-app', 7200, 1800);
$token9 = $jwt9->issue(12345, ['role' => 'admin', 'department' => 'engineering']);
$payload9 = $jwt9->parse($token9);
assert($payload9['role'] === 'admin');
assert($payload9['department'] === 'engineering');
echo "    ✓ 原始 token 包含自定义 claims: role=admin, department=engineering\n";

// 模拟续签：提取非标准声明的自定义 claims
$standardClaims = ['iss', 'sub', 'iat', 'nbf', 'exp', 'jti'];
$customClaims = array_diff_key($payload9, array_flip($standardClaims));
$refreshedToken = $jwt9->issue($payload9['sub'] ?? '', $customClaims);
$refreshedPayload = $jwt9->parse($refreshedToken);
assert($refreshedPayload['role'] === 'admin', 'role should be preserved after refresh');
assert($refreshedPayload['department'] === 'engineering', 'department should be preserved after refresh');
assert($refreshedPayload['sub'] === '12345', 'sub should be preserved after refresh');
echo "    ✓ 续签后自定义 claims 保留：role={$refreshedPayload['role']}, department={$refreshedPayload['department']}\n\n";

// -----------------------------
// 10. RS256 空密钥签名异常测试
// -----------------------------
echo "[10] RS256 空密钥签名异常测试\n";
$rsEmpty = new RS256Signer('', '');
$rsSignThrew = false;
try {
    $rsEmpty->sign('header', 'payload');
} catch (JwtException $e) {
    $rsSignThrew = true;
    echo "    ✓ 空私钥签名抛出异常：{$e->getMessage()}\n";
}
assert($rsSignThrew, 'RS256Signer::sign should throw for empty private key');

$rsVerifyThrew = false;
try {
    $rsEmpty->verify('header', 'payload', 'sig');
} catch (JwtException $e) {
    $rsVerifyThrew = true;
    echo "    ✓ 空公钥验签抛出异常：{$e->getMessage()}\n";
}
assert($rsVerifyThrew, 'RS256Signer::verify should throw for empty public key');
echo "\n";

echo "==========================================\n";
echo "  ✓ 所有核心功能测试通过\n";
echo "==========================================\n";
