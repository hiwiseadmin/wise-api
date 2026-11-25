# wise-api 代码审查报告

> 审查时间：2025-07-12
> 审查范围：Bug 修复、安全审查、性能优化、类型补全

## 变更摘要

| # | 类别 | 文件 | 问题描述 | 修复方式 |
|---|------|------|----------|----------|
| 1.1 | Bug | `src/rate/RateLimiter.php` | `use think\request\Request` 命名空间错误 | 改为 `use think\Request` |
| 1.2 | Bug | `src/rate/RateLimiter.php` | `resolveRule` 中 `$prefix === $path` 重复条件 | 去掉末尾重复的 `\|\| $prefix === $path` |
| 1.3 | Bug | `src/sign/SignVerifier.php` | `Cache::store('redis')->set($key, 1, $tolerance, 'NX')` 不支持第4参数 | 改用 `Cache::store('redis')->handler()->set($key, 1, ['ex' => $tolerance, 'nx' => true])` |
| 1.4 | Bug | `src/rate/SlidingWindow.php` | ThinkPHP Cache facade 没有 `eval()` 方法 | 改用 `Cache::store('redis')->handler()->eval(...)` |
| 1.4 | Bug | `src/rate/TokenBucket.php` | 同上，`eval()` 方法不兼容 | 改用 `Cache::store('redis')->handler()->eval(...)` |
| 1.5 | Bug | `src/middleware/JwtAuthMiddleware.php` | JWT 续签时只保留 `custom_claims`，根级自定义字段丢失 | 用 `array_diff_key` 提取非标准声明，续签时传入 |
| 1.6 | Bug | `src/ApiController.php` | `validateRequest` 不处理 JSON 请求体 | 增加 `application/json` Content-Type 检测与 JSON body 解析 |
| 2.1 | 安全 | `src/jwt/HS256Signer.php` | 密钥 < 8 字节仅 E_USER_NOTICE，过弱 | < 16 字节抛 JwtException，< 32 字节 E_USER_WARNING |
| 2.2 | 安全 | `src/debug/DebugPanel.php` | 调试快照中可能暴露敏感字段 | 添加 `filterSensitive()` 方法，过滤 password/secret/token 等字段 |
| 2.3 | 安全 | `src/jwt/RS256Signer.php` | 缺少析构函数释放 OpenSSL 资源 | 添加 `__destruct()` 调用 `openssl_pkey_free()` |
| 2.4 | 安全 | `src/jwt/Jwt.php` | 缺少 audience (aud) 声明支持 | 构造函数增加 `?string $audience` 参数，`issue()` 中设置 `aud` |
| 2.5 | 安全 | `src/Service.php` | 未检测默认/不安全密钥 | `register()` 中增加 JWT 和 Sign 默认密钥检测并 E_USER_WARNING |
| 2.6 | 安全 | `src/debug/DebugPanel.php` | 缓存键 `wise_api_debug_last` 无命名空间 | 改为 `wise_api:{app}:debug_last`，避免多应用冲突 |
| 3.1 | 性能 | `src/debug/DebugPanel.php` | `attachToResponse` 多次调用 `snapshot()` | 只调用一次，缓存到临时变量 |
| 3.2 | 性能 | `src/debug/DebugPanel.php` | `collectSql()` 用 `file()` 可能读取超大文件 | 改用 `fopen/fgets` 限制最大 500 行 |
| 3.3 | 性能 | `src/rate/RateLimiter.php` | `hit()` 中 `pathinfo()` 被多次调用 | 在 `hit()` 中计算一次 `$path`，传入 `resolveRule()` 和 `buildKey()` |
| 4.1 | 类型 | `src/jwt/RS256Signer.php` | 属性类型为 `resource\|null`，PHP 8.1+ 应为 `OpenSSLAsymmetricKey` | 改为 `?\OpenSSLAsymmetricKey` |
| 4.2 | 类型 | `src/jwt/Jwt.php` | `json_encode` 无错误处理 | 添加 `JSON_THROW_ON_ERROR` 标志 |
| 4.3 | 类型 | `src/sign/SignVerifier.php` | `json_encode` 无错误处理 | 添加 `JSON_THROW_ON_ERROR \| JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES` |
| 4.4 | 类型 | `src/response/ResponseWrapper.php` | 属性类型声明 | 已确认：`int $successCode`, `string $successMsg`, `bool $withTimestamp` ✓ |
| 4.5 | 类型 | `src/jwt/Jwt.php` | 构造函数缺少 audience 参数 | 已添加 `?string $audience = null`，`issue()` 中设置 `aud` 声明 ✓ |
| 4.6 | 类型 | `src/debug/DebugPanel.php` | 属性类型声明 | 已确认：`bool $enabled`, `string $output`, `bool $withHeader` ✓ |

## 测试更新

在 `tests/standalone-test.php` 中新增以下测试：

| # | 测试项 | 验证内容 |
|---|--------|----------|
| 7 | HS256 短密钥异常 | < 16 字节抛 JwtException，16-31 字节 E_USER_WARNING，32+ 无异常 |
| 8 | JWT audience 声明 | 有 audience 时 payload 含 `aud`，无 audience 时不含 `aud` |
| 9 | 续签自定义 claims 保留 | 续签后 `role`、`department` 等根级自定义字段不丢失 |
| 10 | RS256 空密钥签名异常 | 空私钥 `sign()` 抛异常，空公钥 `verify()` 抛异常 |

## 向后兼容性

- 所有修改保持向后兼容，公共 API 签名不变
- `Jwt::__construct` 新增 `?string $audience = null` 可选参数，默认值 null 不影响现有调用
- `RateLimiter::resolveRule` 和 `buildKey` 签名变更接受 `string $path` 参数（内部方法，非公共 API）
- `DebugPanel::getLastSnapshot` 缓存键变更，需清除旧缓存键（无功能影响）
