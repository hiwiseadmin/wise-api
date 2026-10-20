<?php

declare(strict_types=1);

namespace wise\api;

use think\App;
use think\facade\Config;
use wise\api\command\KeyRotate;
use wise\api\config\EncryptionKeyCheck;
use wise\api\config\RedisAvailabilityCheck;
use wise\api\config\SignSecretCheck;
use wise\api\dispatcher\LogDispatcher;
use wise\api\driver\DatabaseDriver;
use wise\api\driver\FileDriver;
use wise\api\driver\QueueDriver;
use wise\api\encrypt\Cipher;
use wise\api\encrypt\KeyManager;
use wise\api\encrypt\SessionKeyStore;
use wise\api\rate\RateLimitCoordinator;
use wise\api\rate\SlidingWindow;
use wise\api\rate\TokenBucket;
use wise\api\response\ResponseWrapper;
use wise\api\service\LoggerService;
use wise\api\sign\SignVerifier;
use wise\api\webhook\WebhookManager;
use wise\api\debug\DebugPanel;
use wise\support\config\ConfigCheckCollector;
use wise\support\contracts\EnvSeedProvider;
use wise\support\traits\ServiceTrait;

/**
 * WiseApi 服务注册
 */
class ApiServiceProvider extends \think\Service implements EnvSeedProvider
{
    use \wise\support\traits\RegistersMigrations;
    use ServiceTrait;

    /**
     * 声明需要播种到 .env 的密钥（安装时自动生成，重装不覆盖）
     *
     * - JWT_SECRET / JWT_SIGN_SECRET：接口 JWT 与请求签名密钥（替换原 'please-change-me' 占位）。
     * - API_ENC_* 密钥对：传输加密 RSA 密钥对。默认加密未启用，但启用即需；
     *   此处自动生成并写入 runtime/keys（私钥 0600），env 中以 file:// 绝对路径引用，
     *   契合 config 中 file:// 的安全存储约定，避免把多行 PEM 直接塞进 .env。
     */
    public static function envSeeds(): array
    {
        return [
            'JWT_SECRET' => [
                'type'      => 'secret',
                'generator' => 'random_bytes_32',
                'comment'   => 'wise-api 接口 JWT 签名密钥',
            ],
            'JWT_SIGN_SECRET' => [
                'type'      => 'secret',
                'generator' => 'random_bytes_32',
                'comment'   => 'wise-api 请求签名密钥',
            ],
            '__api_enc_keypair' => [
                'type'      => 'secret',
                'generator' => 'rsa_2048_pem',
                'keys'      => ['private' => 'API_ENC_PRIVATE_KEY', 'public' => 'API_ENC_PUBLIC_KEY'],
                'file_dir'  => 'runtime/keys',
                'comment'   => 'wise-api 传输加密 RSA 密钥对（自动生成，存于 runtime/keys）',
            ],
        ];
    }

    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->mergeConfig('wise-api');

        // 注册配置检查项 → ConfigCheckCollector 统一管理和输出
        $this->app->make(ConfigCheckCollector::class)
        ->register(new SignSecretCheck($this->app->config))
        ->register(new RedisAvailabilityCheck($this->app))
        ->register(new EncryptionKeyCheck($this->app->config));

        $this->app->bind(ResponseWrapper::class, ResponseWrapper::class);
        $this->app->bind(SignVerifier::class, function (App $app) {
            $verifier = new SignVerifier();
            // P3: 统一 Redis key 前缀为 wise_api:{app}:sign
            $appPrefix = (string) $app->config->get('wise-api.rate_limit.app_prefix', 'default');
            $verifier->setKeyPrefix('wise_api:' . $appPrefix . ':sign');
            return $verifier;
        });
        $this->app->bind(TokenBucket::class, function () {
            $bucket = new TokenBucket();
            $bucket->setFallbackLimiter(new \wise\support\rate\RateLimiter(60, 60));
            return $bucket;
        });
        $this->app->bind(SlidingWindow::class, function () {
            $window = new SlidingWindow();
            $window->setFallbackLimiter(new \wise\support\rate\RateLimiter(60, 60));
            return $window;
        });
        $this->app->bind(RateLimitCoordinator::class, function (App $app) {
            return new RateLimitCoordinator(
                $app->make(TokenBucket::class),
                $app->make(SlidingWindow::class),
                $app->config->get('wise-api.rate_limit', [])
            );
        });
        $this->app->bind(DebugPanel::class, DebugPanel::class);

        // 传输加密组件
        $this->app->bind(Cipher::class, Cipher::class);
        $this->app->bind(KeyManager::class, function (App $app): KeyManager {
            $km = new KeyManager($app->config->get('wise-api.encryption', []));
            $km->loadKeys();
            return $km;
        });

        $this->app->bind(SessionKeyStore::class, function (App $app): SessionKeyStore {
            return new SessionKeyStore($app->config->get('wise-api.encryption.session', []));
        });

        $this->app->bind(WebhookManager::class, function (App $app): WebhookManager {
            return new WebhookManager(
                $app->cache->store('redis'),
                $app->get(\Psr\Log\LoggerInterface::class),
                $app->config->get('wise-api.webhook', [])
            );
        });

        $this->app->bind(LogDispatcher::class, function (App $app): LogDispatcher {
            $dispatcher = new LogDispatcher($app->get(\Psr\Log\LoggerInterface::class));
            $this->registerDrivers($dispatcher);
            return $dispatcher;
        });

        $this->app->bind(LoggerService::class, function (App $app): LoggerService {
            return new LoggerService($app->get(LogDispatcher::class));
        });

        $this->commands([KeyRotate::class]);
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        $this->registerMigrations();
        RouteRegistrar::registerAdmin();
        RouteRegistrar::registerApi();

        // 后台「接口日志」页面路由（/admin/api_log/*，配置驱动）
        AdminRouteRegistrar::register();

        // 声明后台菜单（挂靠框架「日志管理」目录）
        $this->registerAdminMenu();

        if (Config::get('wise-api.logger.auto_create_table', false)) {
            try {
                $connection = $this->app->db->connection(
                    Config::get('wise-api.logger.drivers.database.connection', '')
                );
                $table = 'api_log';
                $databaseDriver = new DatabaseDriver(
                    $connection, $table,
                    Config::get('wise-api.logger.drivers.database.batch_size', 50),
                    $this->app->get(\Psr\Log\LoggerInterface::class)
                );
                $databaseDriver->checkAndAutoCreateTable();
            } catch (\Throwable $e) {
                $this->app->get(\Psr\Log\LoggerInterface::class)->error(
                    '[ApiLogger] 自动建表失败: ' . $e->getMessage()
                );
            }
        }
    }

    private function registerDrivers(LogDispatcher $dispatcher): void
    {
        $driversConfig = Config::get('wise-api.logger.drivers', []);
        $logger = $this->app->get(\Psr\Log\LoggerInterface::class);

        if (!empty($driversConfig['file']['enabled'])) {
            $path = $driversConfig['file']['path'] ?? '';
            if (empty($path)) {
                $path = $this->app->getRuntimePath() . 'api_log';
            }
            $dispatcher->addDriver(new FileDriver(
                $path,
                $driversConfig['file']['max_files'] ?? 30,
                $driversConfig['file']['max_file_size'] ?? 52428800,
                $logger
            ));
        }

        if (!empty($driversConfig['database']['enabled'])) {
            $connection = $this->app->db->connection($driversConfig['database']['connection'] ?? '');
            $dispatcher->addDriver(new DatabaseDriver(
                $connection,
                'api_log',
                $driversConfig['database']['batch_size'] ?? 50,
                $logger
            ));
        }

        if (!empty($driversConfig['queue']['enabled'])) {
            $dispatcher->addDriver(new QueueDriver(
                $driversConfig['queue']['queue_name'] ?? 'api_log',
                $driversConfig['queue']['job_class'] ?? '',
                $driversConfig['queue']['delay'] ?? 0,
                $logger
            ));
        }
    }

    /**
     * 声明后台「接口日志」菜单（ExtPoints::MENU_ITEM）
     *
     * 菜单在包内 ApiLogMenuProvider 声明，由宿主
     * app\admin\service\MenuItemSync 幂等落库并自动派生权限。
     * 落库执行：`php think menu:sync`（声明变化时后台也会懒同步）。
     */
    private function registerAdminMenu(): void
    {
        if (!interface_exists(\wise\support\contracts\MenuItemProvider::class)
            || !class_exists(\wise\support\ExtPoint::class)
            || !class_exists(\wise\support\ExtPoints::class)) {
            return;
        }

        try {
            \wise\support\ExtPoint::register(
                \wise\support\ExtPoints::MENU_ITEM,
                'wise-api',
                \wise\api\menu\ApiLogMenuProvider::class
            );
        } catch (\Throwable $e) {
            $this->app->get(\Psr\Log\LoggerInterface::class)->error(
                '[wise-api] 菜单注册失败: ' . $e->getMessage()
            );
        }
    }
}