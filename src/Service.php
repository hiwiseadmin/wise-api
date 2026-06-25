<?php

declare(strict_types=1);

namespace wise\api;

use think\App;
use think\facade\Config;
use wise\api\command\Publish as LogPublish;
use wise\api\circuit\CircuitBreaker;
use wise\api\dispatcher\LogDispatcher;
use wise\api\driver\DatabaseDriver;
use wise\api\driver\FileDriver;
use wise\api\driver\QueueDriver;
use wise\api\service\LoggerService;
use wise\api\rate\RateLimiter;
use wise\api\rate\SlidingWindow;
use wise\api\rate\TokenBucket;
use wise\api\response\ResponseWrapper;
use wise\api\sign\SignVerifier;
use wise\api\token\RefreshTokenManager;
use wise\api\trait\ServiceTrait;
use wise\api\webhook\WebhookManager;

/**
 * WiseApi 服务注册
 */
class Service extends \think\Service
{
    use ServiceTrait;

    /**
     * 注册服务
     */
    public function register(): void
    {
        // 加载并合并配置文件
        $this->mergeConfig('wise-api');

        // 响应包装器（单例）
        $this->app->bind(ResponseWrapper::class, ResponseWrapper::class);

        // 签名验证器（单例）
        $this->app->bind(SignVerifier::class, SignVerifier::class);

        // 限流
        $this->app->bind(TokenBucket::class, TokenBucket::class);
        $this->app->bind(SlidingWindow::class, SlidingWindow::class);
        $this->app->bind(RateLimiter::class, function (App $app) {
            return new RateLimiter(
                $app->make(TokenBucket::class),
                $app->make(SlidingWindow::class),
                $app->config->get('wise-api.rate_limit', []));
        });

        // 调试面板
        $this->app->bind(DebugPanel::class, DebugPanel::class);

        // Refresh Token 管理器
        $this->app->bind(RefreshTokenManager::class, function (App $app): RefreshTokenManager {
            return new RefreshTokenManager(
                $app->cache->store('redis'),
                [
                    'at_ttl' => (int) $app->config->get('wise-api.refresh_token.at_ttl', 900),
                    'rt_ttl' => (int) $app->config->get('wise-api.refresh_token.rt_ttl', 604800),
                    'secret' => (string) $app->config->get('app.app_key', ''),
                ]
            );
        });

        // Webhook 管理器
        $this->app->bind(WebhookManager::class, function (App $app): WebhookManager {
            return new WebhookManager(
                $app->cache->store('redis'),
                $app->get(\Psr\Log\LoggerInterface::class),
                $app->config->get('wise-api.webhook', [])
            );
        });

        // JWT 功能已迁移至 wise-jwt 包，由 \wise\jwt\Service 自行完成绑定
        // wise-api 的 \wise\api\jwt\* 类保留为兼容性薄包装（@deprecated）

        // ====================================================================
        // API 日志记录器（合并自 wise-api-logger）
        // ====================================================================

        // 绑定 LogDispatcher 到容器（单例）
        $this->app->bind(LogDispatcher::class, function (App $app): LogDispatcher {
            $dispatcher = new LogDispatcher($app->get(\Psr\Log\LoggerInterface::class));
            $this->registerDrivers($dispatcher);
            return $dispatcher;
        });

        // 绑定 LoggerService 到容器
        $this->app->bind(LoggerService::class, function (App $app): LoggerService {
            return new LoggerService($app->get(LogDispatcher::class));
        });

        // 注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                LogPublish::class,
            ]);
        }
    }

    /**
     * 启动：注册调试路由 / 自动建表
     */
    public function boot(): void
    {
        // 注册调试/管理路由
        RouteRegistrar::registerAdmin();

        // 如果配置 auto_create_table 为 true，执行自动建表
        if (Config::get('wise-api.logger.auto_create_table', false)) {
            try {
                $connection = $this->app->db->connection(
                    Config::get('wise-api.logger.drivers.database.connection', '')
                );
                $table = Config::get('wise-api.table_names.api_log', 'api_log');
                $databaseDriver = new DatabaseDriver(
                    $connection,
                    $table,
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

    /**
     * 根据配置创建启用的驱动并注册到 Dispatcher
     */
    private function registerDrivers(LogDispatcher $dispatcher): void
    {
        $driversConfig = Config::get('wise-api.logger.drivers', []);
        $logger = $this->app->get(\Psr\Log\LoggerInterface::class);

        // 文件驱动
        if (!empty($driversConfig['file']['enabled'])) {
            $path = $driversConfig['file']['path'] ?? '';
            if (empty($path)) {
                $path = $this->app->getRuntimePath() . 'api_log';
            }
            $maxFiles = $driversConfig['file']['max_files'] ?? 30;
            $maxFileSize = $driversConfig['file']['max_file_size'] ?? 52428800;
            $fileDriver = new FileDriver($path, $maxFiles, $maxFileSize, $logger);
            $dispatcher->addDriver($fileDriver);
        }

        // 数据库驱动
        if (!empty($driversConfig['database']['enabled'])) {
            $connection = $this->app->db->connection(
                $driversConfig['database']['connection'] ?? ''
            );
            $table = Config::get('wise-api.table_names.api_log', 'api_log');
            $batchSize = $driversConfig['database']['batch_size'] ?? 50;
            $databaseDriver = new DatabaseDriver($connection, $table, $batchSize, $logger);
            $dispatcher->addDriver($databaseDriver);
        }

        // 队列驱动
        if (!empty($driversConfig['queue']['enabled'])) {
            $queueName = $driversConfig['queue']['queue_name'] ?? 'api_log';
            $jobClass = $driversConfig['queue']['job_class'] ?? '';
            $delay = $driversConfig['queue']['delay'] ?? 0;
            $queueDriver = new QueueDriver($queueName, $jobClass, $delay, $logger);
            $dispatcher->addDriver($queueDriver);
        }
    }
}
