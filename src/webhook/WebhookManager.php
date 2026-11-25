<?php
declare(strict_types=1);

namespace wise\api\webhook;

use think\Cache;
use Psr\Log\LoggerInterface;
use wise\webhook\server\WebhookCall;
use wise\support\backoff\BackoffStrategy;

/**
 * Webhook 管理器 — 委托至 wise-webhook
 *
 * 保留 wise-api 原有的 register/fire 动态注册 API，
 * 实际发送逻辑委托至 {@see \wise\webhook\server\WebhookCall}。
 *
 * 用法：
 *   $wh = app(WebhookManager::class);
 *
 *   // 注册
 *   $wh->register('order.paid', 'https://example.com/webhook', 'my-secret');
 *
 *   // 触发（在业务事件中调用）
 *   $wh->fire('order.paid', ['order_id' => 123, 'amount' => 99.00]);
 *
 *   // 查询投递日志
 *   $logs = $wh->getLogs('order.paid');
 *
 * @package wise\api\webhook
 */
class WebhookManager
{
    /** @var int 最大重试次数 */
    protected int $maxRetries = 3;

    /** @var int 连续失败多少次后自动停用 */
    protected int $disableAfterFailures = 10;

    /** @var int 请求超时/秒 */
    protected int $timeout = 10;

    /** @var int 连接超时/秒 */
    protected int $connectTimeout = 5;

    /**
     * 是否异步投递（入队后由队列 worker 负责重试）
     *
     * 同步投递最坏 3×(5+10)+1+3+9 ≈ 58s 阻塞在当前请求内：FPM 下是 504，
     * Swoole 下会把整个 worker 的所有协程一起拖死。生产环境建议开启。
     *
     * @var bool
     */
    protected bool $async = false;

    /** @var string 异步投递使用的队列名 */
    protected string $queueName = 'api_webhook';

    /**
     * @param Cache|object          $cache   缓存实例
     * @param LoggerInterface|null  $logger  日志实例
     * @param array                 $options 选项
     */
    public function __construct(
        protected $cache,
        protected ?LoggerInterface $logger = null,
        protected array $options = []
    ) {
        $this->maxRetries            = (int) ($options['max_retries'] ?? 3);
        $this->disableAfterFailures  = (int) ($options['disable_after_failures'] ?? 10);
        $this->timeout               = (int) ($options['timeout'] ?? 10);
        $this->connectTimeout        = (int) ($options['connect_timeout'] ?? 5);
        $this->async                 = (bool) ($options['async'] ?? false);
        $this->queueName             = (string) ($options['queue'] ?? 'api_webhook');
    }

    /**
     * 同步投递时的重试次数
     *
     * 同步模式下重试会直接放大请求耗时（每次重试 = 连接超时 + 读超时 + 退避），
     * 因此强制收敛为 1 次；完整的重试策略交给异步队列。
     *
     * @return int
     */
    protected function retryTimes(): int
    {
        return $this->async ? $this->maxRetries : min(1, $this->maxRetries);
    }

    /**
     * 注册 Webhook
     *
     * @param string $event  事件名（如 'order.paid'）
     * @param string $url    回调 URL
     * @param string $secret 签名密钥（用于 HMAC 签名）
     * @param array  $meta   自定义元数据
     */
    public function register(string $event, string $url, string $secret = '', array $meta = []): string
    {
        $id = $this->generateId();

        $webhook = [
            'id'          => $id,
            'event'       => $event,
            'url'         => $url,
            'secret'      => $secret ?: bin2hex(random_bytes(16)),
            'meta'        => $meta,
            'status'      => 'active',
            'create_time' => time(),
            'failures'    => 0,
        ];

        $registrations = $this->cache->get($this->key("reg:{$event}"), []);
        $registrations[$id] = $webhook;
        $this->cache->set($this->key("reg:{$event}"), $registrations, 0);

        $all = $this->cache->get($this->key('reg:all'), []);
        $all[$id] = ['event' => $event, 'url' => $url, 'status' => 'active'];
        $this->cache->set($this->key('reg:all'), $all, 0);

        return $id;
    }

    /**
     * 取消注册
     */
    public function unregister(string $event, string $id): bool
    {
        $registrations = $this->cache->get($this->key("reg:{$event}"), []);
        if (!isset($registrations[$id])) {
            return false;
        }
        unset($registrations[$id]);
        $this->cache->set($this->key("reg:{$event}"), $registrations, 0);

        $all = $this->cache->get($this->key('reg:all'), []);
        unset($all[$id]);
        $this->cache->set($this->key('reg:all'), $all, 0);

        return true;
    }

    /**
     * 触发事件（向所有注册的 Webhook 投递）
     *
     * @param string $event   事件名
     * @param array  $payload 业务数据
     * @return array<int, array> 投递结果汇总
     */
    public function fire(string $event, array $payload): array
    {
        if (empty($this->options['enable'])) {
            return [];
        }

        $registrations = $this->cache->get($this->key("reg:{$event}"), []);
        if (empty($registrations)) {
            return [];
        }

        $results = [];
        foreach ($registrations as $id => $webhook) {
            if (($webhook['status'] ?? 'active') === 'disabled') {
                continue;
            }
            $results[] = $this->deliver($id, $webhook, $event, $payload);
        }

        return $results;
    }

    /**
     * 单次投递 — 委托至 wise-webhook 的 WebhookCall
     *
     * WebhookCall 内部已处理：签名计算、重试、退避、数据库持久化。
     * 本方法只负责：结果汇总、失败计数、Cache 日志。
     */
    protected function deliver(string $id, array $webhook, string $event, array $payload): array
    {
        $url    = $webhook['url'];
        $secret = $webhook['secret'] ?? '';

        if ($this->async) {
            try {
                \think\facade\Queue::push(
                    \wise\api\job\ApiWebhookJob::class,
                    [
                        'id'             => $id,
                        'url'            => $url,
                        'event'          => $event,
                        'payload'        => $payload,
                        'secret'         => $secret,
                        'timeout'        => $this->timeout,
                        'connectTimeout' => $this->connectTimeout,
                        'maxRetries'     => $this->maxRetries,
                    ],
                    $this->queueName
                );

                return [
                    'id'        => $id,
                    'url'       => $url,
                    'event'     => $event,
                    'status'    => 'queued',
                    'http_code' => 0,
                    'attempts'  => 0,
                ];
            } catch (\Throwable $e) {
                $this->logger?->warning('[webhook] 入队失败，降级为同步投递: ' . $e->getMessage());
            }
        }

        try {
            $result = WebhookCall::create()
                ->url($url)
                ->payload([
                    'event'   => $event,
                    'payload' => $payload,
                    'id'      => $id,
                    'ts'      => time(),
                ])
                ->useSecret($secret)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->maxRetries($this->retryTimes())
                ->backoffStrategy(BackoffStrategy::exponential(1000, 9000))
                ->dispatchSync();

            $response = [
                'id'        => $id,
                'url'       => $url,
                'event'     => $event,
                'status'    => $result['success'] ? 'success' : 'failed',
                'http_code' => $result['code'] ?? 0,
                'attempts'  => 1,
            ];

            if (!$result['success']) {
                $response['error'] = $result['response'] ?? 'HTTP ' . ($result['code'] ?? 0);
            }

        } catch (\Throwable $e) {
            $response = [
                'id'        => $id,
                'url'       => $url,
                'event'     => $event,
                'status'    => 'failed',
                'http_code' => 0,
                'attempts'  => 1,
                'error'     => $e->getMessage(),
            ];
        }

        $this->trackFailureStatus($id, $event, $webhook, $response['status'] === 'success');
        $this->logDelivery($id, $event, $url, $response);

        return $response;
    }

    /**
     * 上报一次投递结果（供异步队列 worker 调用）
     *
     * @param string $id      注册 ID
     * @param string $event   事件名
     * @param bool   $success 是否投递成功
     * @return void
     */
    public function reportDeliveryResult(string $id, string $event, bool $success): void
    {
        $this->trackFailureStatus($id, $event, [], $success);
    }

    /**
     * 跟踪失败状态：成功时重置，失败时递增，超阈值自动停用
     */
    protected function trackFailureStatus(string $id, string $event, array $webhook, bool $success): void
    {
        $failKey = $this->key("fail:{$event}:{$id}");

        $registrations = $this->cache->get($this->key("reg:{$event}"), []);
        if (!isset($registrations[$id])) {
            return;
        }

        if ($success) {
            $this->cache->delete($failKey);

            if ((int) ($registrations[$id]['failures'] ?? 0) === 0
                && ($registrations[$id]['status'] ?? 'active') === 'active') {
                return;
            }

            $registrations[$id]['failures'] = 0;
            $registrations[$id]['status']   = 'active';
        } else {
            $failures = (int) $this->cache->inc($failKey);
            if ($failures < $this->disableAfterFailures) {
                return;
            }

            $registrations[$id]['failures']    = $failures;
            $registrations[$id]['status']      = 'disabled';
            $registrations[$id]['disabled_at'] = time();
        }

        $this->cache->set($this->key("reg:{$event}"), $registrations, 0);

        $all = $this->cache->get($this->key('reg:all'), []);
        if (isset($all[$id])) {
            $all[$id]['status'] = $registrations[$id]['status'];
            $this->cache->set($this->key('reg:all'), $all, 0);
        }
    }

    /**
     * 记录投递日志
     */
    protected function logDelivery(string $id, string $event, string $url, array $result): void
    {
        $log = array_merge($result, ['logged_at' => date('c')]);

        $logs = $this->cache->get($this->key("log:{$id}"), []);
        array_unshift($logs, $log);
        $logs = array_slice($logs, 0, 100);
        $this->cache->set($this->key("log:{$id}"), $logs, 86400 * 7);

        $this->logger?->info("[Webhook] {$event} → {$url}", $log);
    }

    /**
     * 获取指定 Webhook 的投递日志
     */
    public function getLogs(string $id): array
    {
        return $this->cache->get($this->key("log:{$id}"), []);
    }

    /**
     * 获取所有注册的 Webhook
     */
    public function getAll(): array
    {
        return $this->cache->get($this->key('reg:all'), []);
    }

    /**
     * 获取某事件的所有注册
     */
    public function getByEvent(string $event): array
    {
        return $this->cache->get($this->key("reg:{$event}"), []);
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    protected function key(string $suffix): string
    {
        return "wh:{$suffix}";
    }
}
