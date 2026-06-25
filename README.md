# wiseadmin/wise-api

> 为 ThinkPHP 6.0+ 或 8.0+ 提供的 API 快速开发工具包——开箱即用的限流、签名、JWT 鉴权、统一响应、字段过滤与分页包装、Debug 模式调试面板。

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--api-orange)](https://packagist.org/packages/wiseadmin/wise-api)

---

## 特性

| 模块 | 说明 |
|------|------|
| **API 版本管理** | 通过 URL 前缀 `api/v1`、`api/v2` 自动路由到不同子命名空间 |
| **接口限流** | 令牌桶 + 滑动窗口双算法，原生 Redis Lua 脚本保证原子性 |
| **签名验证** | HMAC-SHA256，支持时间戳防重放 + Nonce 防重放 |
| **JWT 鉴权** | 纯 PHP 实现 HS256/RS256，零外部依赖，支持自动续签 |
| **统一响应** | `{code, msg, data, timestamp}` 结构，错误码枚举 |
| **字段过滤** | `?fields=id,name` + `allowFields()` 显式过滤 |
| **统一分页** | `{list, total, page, page_size}` 自动包装 |
| **调试面板** | Debug 模式下记录耗时、内存、限流状态、JWT、签名过程、SQL 日志 |

---

## 安装

```bash
composer require wiseadmin/wise-api
```

包通过 `composer.json` 的 `extra.think.services` 自动注册服务提供者，无需手动配置。

---

## 配置

将 `vendor/wiseadmin/wise-api/config/wise-api.php` 复制到项目 `config/` 目录后按需修改：

```php
// config/wise-api.php
return [
    'jwt' => [
        'algorithm'   => 'HS256',
        'secret'      => env('JWT_SECRET', 'change-me'),
        'ttl'         => 7200,
        'refresh_ttl' => 1800,
    ],
    'sign' => [
        'enable'        => true,
        'secret'        => env('SIGN_SECRET', 'change-me'),
        'timestamp_ttl' => 300,
        'enable_nonce'  => true,
    ],
    'rate_limit' => [
        'enable'    => true,
        'algorithm' => 'token_bucket',
        'key_by'    => 'combine',
        'default'   => ['algorithm' => 'token_bucket', 'capacity' => 60, 'rate' => 1.0],
        'groups'    => [
            // 'api/v1/login' => ['algorithm' => 'sliding_window', 'limit' => 10, 'window' => 60],
        ],
    ],
    // ...
];
```

---

## 基础用法

### 1. 控制器继承 ApiController

```php
namespace app\api\controller\v1;

use think\model\User;
use wise\api\ApiController;
use wise\api\middleware\RateLimitMiddleware;
use wise\api\middleware\SignVerifyMiddleware;
use wise\api\middleware\JwtAuthMiddleware;

class UserController extends ApiController
{
    /**
     * 中间件栈
     * - 限流：60 个令牌，1 个/秒
     * - 签名验证
     * - JWT 鉴权
     */
    protected $middleware = [
        RateLimitMiddleware::class . ':60,1',
        SignVerifyMiddleware::class,
        JwtAuthMiddleware::class,
    ];

    /**
     * GET /api/v1/users
     */
    public function index()
    {
        $params = $this->validateRequest([
            'page'      => 'integer|egt:1',
            'page_size' => 'integer|between:1,100',
            'keyword'   => 'chs',
        ]);
        $query = User::order('id desc');
        if (!empty($params['keyword'])) {
            $query->where('name', 'like', "%{$params['keyword']}%");
        }
        return $this->paginate($query, (int) ($params['page_size'] ?? 15));
    }

    /**
     * GET /api/v1/users/5?fields=id,name,email
     */
    public function read($id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->error(4001, '用户不存在');
        }
        $fields = $this->resolveFields(['id', 'name', 'email', 'create_time']);
        return $this->success($this->allowFields($fields, $user));
    }

    /**
     * POST /api/v1/users
     */
    public function save()
    {
        $params = $this->validateRequest([
            'name'  => 'require|chs|length:2,20',
            'email' => 'require|email',
        ]);
        $user = User::create($params);
        return $this->success(['id' => $user->id], '创建成功');
    }
}
```

### 2. 路由（API 版本管理）

```php
// app/api/route/v1.php
use think\Route;

Route::group('v1', function () {
    Route::resource('users', 'v1.User');
});
```

业务请求 `/api/v1/users` 自动路由到 `app\api\controller\v1\UserController`。
增加 `/api/v2/users` 只需新建 `app\api\controller\v2\UserController` + `app/api/route/v2.php`。

### 3. JWT 签发与解析

```php
use wise\api\facade\Jwt;

// 签发
$token = Jwt::issue($userId, ['role' => 'admin', 'custom_claims' => ['role' => 'admin']]);
// → eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.xxx.yyy

// 解析（带完整校验）
$payload = Jwt::parse($token);
echo $payload['sub']; // 用户 ID
echo $payload['role']; // 业务自定义字段

// 安全解析（失败返回 null）
$payload = Jwt::tryParse($token);
```

### 4. 在控制器获取当前登录用户

```php
$user = $this->currentUser();
// ['id' => '123', 'payload' => [...]]
```

### 5. 手动签名（供业务方主动签名时复用）

```php
use wise\api\sign\SignVerifier;

$verifier = new SignVerifier();
$params = ['app_key' => 'demo', 'timestamp' => time(), 'nonce' => 'xyz', 'foo' => 'bar'];
$sign = $verifier->sign($params, 'your-secret');
// 客户端将 sign 追加到 params 后发起请求即可
```

### 6. 限流分组

```php
// config/wise-api.php
'rate_limit' => [
    'groups' => [
        'api/v1/login' => ['algorithm' => 'sliding_window', 'limit' => 10, 'window' => 60],
        'api/v1/sms'   => ['algorithm' => 'token_bucket',   'capacity' => 5, 'rate' => 0.1],
    ],
],
```

匹配到分组时，相应 API 自动使用分组配置；未匹配时使用 `default`。

### 7. 自动续签

`JwtAuthMiddleware` 在剩余有效期 < `refresh_ttl` 时，会在响应头自动添加：

```
X-New-Token: eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.xxx.yyy
```

客户端在 `axios/fetch` 拦截器中替换本地 token 即可。

---

## 调试面板（仅 Debug 模式）

启动应用时调用 `app()->isDebug()` 必须为 true。启用后：

- 每次请求的 Header 中附带 `X-Debug-Info`（base64 编码的 JSON）
- 访问 `GET /__debug_api` 返回最近一次请求的完整调试快照
- 包含：耗时、内存、限流状态、JWT payload、签名原始串与本地计算结果、SQL 日志

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "elapsed_ms": 42,
    "memory": { "start": 2097152, "current": 3145728, "peak": 4194304 },
    "context": {
      "rate_limit": { "key": "...", "algorithm": "token_bucket", "allowed": true, "info": {...} },
      "sign":       { "raw_string": "...", "local_sign": "..." },
      "jwt":        { "sub": "123", "exp": 1718256000, "ttl": 1700, "should_refresh": true }
    },
    "sql": ["[ SQL ] SELECT * FROM ..."]
  }
}
```

> 生产环境 `app()->isDebug()` 为 false 时，`/__debug_api` 路由自动不注册。

---

## 架构概览

```
src/
├── ApiController.php          控制器基类
├── Service.php                服务注册
├── helper.php                 助手函数
├── middleware/                限流 / 签名 / JWT 中间件
├── jwt/                       JWT 核心 + HS256 / RS256
├── rate/                      令牌桶 / 滑动窗口 / 协调者
├── sign/                      签名验证器
├── response/                  统一响应 + 错误码枚举
├── debug/                     调试面板
├── exception/                 JWT 异常
└── facade/                    JwtFacade
config/wise-api.php             配置文件
route/wise.php                 默认路由（debug 模式）
```

---

## 错误码

| Code | HTTP | 含义 |
|------|------|------|
| 0    | 200  | 成功 |
| 1000 | 500  | 未知错误 |
| 1001 | 400  | 参数无效 |
| 2000 | 401  | 未鉴权 |
| 2001 | 401  | Token 无效 |
| 2002 | 401  | Token 过期 |
| 2100 | 400  | 签名错误 |
| 2101 | 400  | 时间戳超出容差 |
| 2102 | 400  | Nonce 重复 |
| 2200 | 403  | 无权限 |
| 3000 | 429  | 触发限流 |
| 4001 | 404  | 数据不存在 |
| 4002 | 409  | 数据冲突 |
| 9000 | 502  | 上游错误 |

---

## License

MIT