<?php
declare(strict_types=1);

/**
 * WiseApi 默认配置
 *
 * 复制本文件到项目 config 目录后按需调整。
 * 项目中可通过 `config('wise-api.xxx')` 读取。
 *
 * @package wise\api
 */

return [
    // ====================================================================
    // 数据表名（可自定义避免冲突）
    // ====================================================================
    'table_names' => [
        'api_log' => 'api_log',
    ],

    // ====================================================================
    // JWT 鉴权
    // ====================================================================
    'jwt' => [
        // 当前应用名（多应用隔离密钥时使用）
        'app'         => 'default',
        // 签名算法：HS256 | RS256
        'algorithm'   => 'HS256',
        // HS256 共享密钥（生产环境务必修改，建议从 .env 读取）
        'secret'      => env('JWT_SECRET', 'please-change-me-in-production-32bytes!'),
        // RS256 私钥/公钥 PEM 路径（仅 RS256 时使用）
        'private_key' => env('JWT_PRIVATE_KEY', ''),
        'public_key'  => env('JWT_PUBLIC_KEY', ''),
        // access_token 有效期（秒）
        'ttl'         => 7200,
        // 刷新阈值（秒）：剩余有效期小于该值时自动续签
        'refresh_ttl' => 1800,
        // 签发者
        'issuer'      => 'wise-api',
        // token 提取顺序：header(Authorization Bearer) → cookie(name=wise_token)
        'token_header'=> 'Authorization',
        'token_cookie'=> 'wise_token',
    ],

    // ====================================================================
    // 签名验证
    // ====================================================================
    'sign' => [
        // 是否启用
        'enable'      => true,
        // 签名算法：HMAC-SHA256
        'algorithm'   => 'sha256',
        // 签名密钥（每个 app_key 可对应不同 secret）
        'secret'      => env('JWT_SIGN_SECRET', 'please-change-me-in-production-sign-secret!'),
        // 时间戳容差（秒），超出视为重放攻击
        'timestamp_ttl' => 300,
        // 是否启用 nonce 防重放（基于 Redis 缓存 5 分钟）
        'enable_nonce'  => true,
        // 排除参与签名的字段
        'excludes'      => ['sign'],
        // 签名头名（用于传入 app_key 以支持多应用密钥）
        'app_key_field' => 'app_key',
    ],

    // ====================================================================
    // 限流
    // ====================================================================
    'rate_limit' => [
        // 是否启用
        'enable'    => true,
        // 默认算法：token_bucket | sliding_window
        'algorithm' => 'token_bucket',
        // 限流键生成策略：ip | user_id | api_path | combine(默认 ip+api_path)
        'key_by'    => 'combine',
        // 存储驱动：仅 redis 可用
        'store'     => 'redis',
        // 全局默认配置
        'default'   => [
            'algorithm' => 'token_bucket',
            // 令牌桶：桶容量
            'capacity'  => 60,
            // 令牌桶：每秒填充速率
            'rate'      => 1.0,
        ],
        // 按 API 分组预设（按 api_path 前缀匹配）
        'groups'    => [
            // 'api/v1/login' => ['algorithm' => 'sliding_window', 'limit' => 10, 'window' => 60],
        ],
        // 触发限流时返回的 HTTP 状态码
        'http_code' => 429,
        // 多应用隔离前缀（多应用部署时避免限流键串号）
        // 默认读取 jwt.app，设为 '' 关闭隔离
        'app_prefix' => env('APP_NAME', 'default'),
    ],

    // ====================================================================
    // 统一响应
    // ====================================================================
    'response' => [
        // 业务成功状态码
        'success_code' => 0,
        // 业务默认成功消息
        'success_msg'  => 'success',
        // 是否输出 timestamp 字段
        'with_timestamp' => true,
    ],

    // ====================================================================
    // API 调试面板（仅 Debug 模式可见）
    // ====================================================================
    'debug' => [
        // 是否启用（仅当 app()->isDebug() 为 true 时生效）
        'enable'   => true,
        // 输出方式：response_header | route
        // route 模式会注册 /__debug_api 返回最近一次请求的调试信息
        'output'   => 'route',
        // 是否记录 SQL 日志
        'with_sql' => true,
        // 是否在响应 Header 中附带 X-Debug-Info
        'with_header' => true,
    ],

    // ====================================================================
    // API 版本管理
    // ====================================================================
    'version' => [
        // 是否启用多版本路由
        'enable' => true,
        // 支持的版本号
        'supported' => ['v1', 'v2'],
        // 默认版本（未指定时使用）
        'default' => 'v1',
        // 废弃版本配置（配合 DeprecationMiddleware 使用）
        // 响应头：X-API-Deprecated / X-API-Sunset / X-API-Latest-Version
        'deprecated' => [
            // 'v1' => [
            //     'sunset'  => '2027-01-01',          // 计划下线日期
            //     'message' => 'v1 即将下线，请升级到 v2',
            // ],
        ],
    ],

    // ====================================================================
    // 响应压缩
    // ====================================================================
    'compression' => [
        // 是否启用（配合 CompressionMiddleware 使用）
        'enable' => true,
        // 最小压缩字节数（小于该值不压缩）
        'min_bytes' => 1024,
        // 是否启用 gzip 压缩
        //   - Nginx 已开启 gzip → 建议设为 false（避免 PHP 层冗余，Nginx C 实现更快）
        //   - 无前端代理/CDN → 设为 true
        'gzip_enabled' => true,
        // gzip 压缩级别（0-9，默认 6）
        'gzip_level' => 6,
        // 是否启用 brotli 压缩（需 PHP 安装 brotli 扩展）
        //   brotli 比 gzip 压缩率高 15%-25%，推荐开启
        'brotli_enabled' => true,
        // brotli 压缩级别（0-11，默认 4）
        'brotli_level' => 4,
    ],

    // ====================================================================
    // 请求体大小限制
    // ====================================================================
    'body_size_limit' => [
        // 是否启用（配合 BodySizeLimitMiddleware 使用）
        'enable' => true,
        // 默认限制字节数（1MB）
        'max_bytes' => 1048576,
    ],

    // ====================================================================
    // API 日志记录器
    // ====================================================================
    'logger' => [
        // 全局开关
        'enabled' => true,

        // 默认日志通道
        'default_channel' => 'api',

        // 默认应用标识
        'default_app_key' => '',

        // 请求/响应体最大长度
        'max_body_length' => 65536,

        // 是否记录请求头
        'log_request_headers' => true,

        // 是否记录响应头
        'log_response_headers' => false,

        // 敏感头字段（这些头的值会被替换为 ***）
        'sensitive_headers' => ['authorization', 'cookie', 'x-api-key'],

        // 跳过的路由
        'skip_routes' => [],

        // 跳过的路径（前缀匹配）
        'skip_paths' => ['/health', '/metrics'],

        // 跳过的 HTTP 方法
        'skip_methods' => ['OPTIONS'],

        // 是否自动建表（默认关闭，建议通过 InstallCommand 或迁移文件建表）
        'auto_create_table' => false,

        // 慢请求检测
        'slow_request' => [
            // 全局阈值/毫秒（0 表示关闭检测）
            'threshold_ms' => 1000,
            // 按路径分组阈值（前缀匹配，单位毫秒）
            'groups' => [
                // '/api/v1/export' => 5000,  // 导出类接口放宽
                // '/api/v1/upload' => 10000, // 上传类接口更宽松
            ],
        ],

        // 驱动配置
        'drivers' => [
            // 文件驱动
            'file' => [
                'enabled' => true,
                'path' => '',               // 空则使用 runtime_path() . 'api_log'
                'max_files' => 30,          // 保留最大文件数
                'max_file_size' => 52428800, // 单文件最大 50MB
            ],

            // 数据库驱动
            'database' => [
                'enabled' => false,
                'table' => 'api_log',
                'connection' => '',          // 空则使用默认连接
                'batch_size' => 50,          // 批量写入大小
            ],

            // 队列驱动
            'queue' => [
                'enabled' => false,
                'queue_name' => 'api_log',
                'job_class' => '',           // 队列任务类名，留空则使用包内默认 Job（wise\api\logger\job\ApiLogJob）
                'delay' => 0,               // 延迟时间（秒）
            ],
        ],
    ],

    // ====================================================================
    // IP 过滤（白名单/黑名单，配合 IpWhitelistMiddleware 使用）
    // ====================================================================
    'ip_filter' => [
        // 是否启用
        'enable' => false,
        // 过滤模式：whitelist | blacklist
        'mode'    => 'blacklist',
        // IP 列表（支持单个 IP / CIDR 网段）
        'ips'     => [
            // '192.168.1.100',
            // '10.0.0.0/8',
        ],
        // 是否启用 Redis 动态加载（实时生效，无需重启）
        'redis_enable' => false,
        // Redis 键名（值应为 string[] 类型）
        'redis_key'    => 'wise_api:ip_filter',
    ],

    // ====================================================================
    // Webhook 系统
    // ====================================================================
    'webhook' => [
        // 是否启用
        'enable'          => false,
        // 最大重试次数
        'max_retries'     => 3,
        // 连续失败多少次后自动停用
        'disable_after_failures' => 10,
        // 请求超时/秒
        'timeout'         => 10,
    ],

    // ====================================================================
    // Refresh Token（配合 RefreshTokenManager 使用）
    // ====================================================================
    'refresh_token' => [
        // Access Token 有效期/秒（默认 900 = 15 分钟）
        'at_ttl'  => 900,
        // Refresh Token 有效期/秒（默认 604800 = 7 天）
        'rt_ttl'  => 604800,
    ],
];
