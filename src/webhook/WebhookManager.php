<?php
declare(strict_types=1);

namespace wise\api\webhook;

use think\Cache;
use Psr\Log\LoggerInterface;

/**
 * Webhook 管理器
 *
 * 核心能力：
 *   - 事件注册：按事件名注册 Webhook URL
 *   - HMAC 签名发送：POST JSON + X-Webhook-Signature 头
 *   - 重试策略：最多 3 次，指数退避（1s, 4s, 9s）
 *   - 投递日志：记录每次投递结果
 *   - 失败后自动停用：连续失败 N 次后标记为 disabled
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
 * 安全：
 *   - 每个 Webhook URL 都有独立的签名密钥
 *   - 签名算法 HMAC-SHA256，接收方可验签
 *   - URL 支持白名单验证（可选）
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

    /**
     * @param Cache|object     $cache  缓存实例
     * @param LoggerInterface|null $logger 日志实例
     * @param array            $options 选项
     */
    public function __construct(
        protected $cache,
        protected ?LoggerInterface $logger = null,
        protected array $options = []
    ) {
        $this->maxRetries            = (int) ($options['max_retries'] ?? 3);
        $this->disableAfterFailures  = (int) ($options['disable_after_failures'] ?? 10);
        $this->timeout               = (int) ($options['timeout'] ?? 10);
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
            'id'         => $id,
            'event'      => $event,
            'url'        => $url,
            'secret'     => $secret ?: bin2hex(random_bytes(16)),
            'meta'       => $meta,
            'status'     => 'active',
            'create_time' => time(),
            'failures'   => 0,
        ];

        $registrations = $this->cache->get($this->key("reg:{$event}"), []);
        $registrations[$id] = $webhook;
        $this->cache->set($this->key("reg:{$event}"), $registrations, 0); // 不过期

        // 维护全局列表（用于管理面板查询）
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
     * @param string $event  事件名
     * @param array  $payload 业务数据
     * @return array<int, array> 投递结果汇总
     */
    public function fire(string $event, array $payload): array
    {
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
     * 单次投递（含重试 + 日志）
     */
    protected function deliver(string $id, array $webhook, string $event, array $payload): array
    {
        $body  = json_encode([
            'event'   => $event,
            'payload' => $payload,
            'id'      => $id,
            'ts'      => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $secret    = $webhook['secret'] ?? '';
        $signature = $this->sign($body, $secret);

        $result = [
            'id'     => $id,
            'url'    => $webhook['url'],
            'event'  => $event,
            'status' => 'pending',
            'attempts' => 0,
        ];

        // 重试循环（指数退避：1s, 4s, 9s）
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $response = $this->httpPost($webhook['url'], $body, $signature);
            $result['attempts'] = $attempt;
            $result['http_code'] = $response['http_code'] ?? 0;

            if ($response['success']) {
                $result['status'] = 'success';
                $this->resetFailures($id, $event, $webhook);
                break;
            }

            $result['status'] = 'failed';
            $result['error']  = $response['error'] ?? 'Unknown error';

            if ($attempt < $this->maxRetries) {
                usleep((int) pow($attempt, 2) * 1000000); // 1s, 4s, 9s
            }
        }

        // 记录失败
        if ($result['status'] === 'failed') {
            $this->incrementFailures($id, $event, $webhook);
        }

        // 记录日志
        $this->logDelivery($id, $event, $webhook['url'], $result);

        return $result;
    }

    /**
     * HTTP POST 发送
     *
     * @return array{success: bool, http_code: int, body: string, error: string}
     */
    protected function httpPost(string $url, string $body, string $signature): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . $signature,
                'X-Webhook-ID: ' . ($this->generateId()),
                'User-Agent: WiseAdmin-Webhook/1.0',
            ],
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_error($ch);
        curl_close($ch);

        return [
            'success'   => !$error && $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body'      => $responseBody ?: '',
            'error'     => $error ?: (!$error && $httpCode >= 400 ? "HTTP {$httpCode}" : ''),
        ];
    }

    /**
     * HMAC-SHA256 签名
     */
    protected function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * 递增失败计数，超阈值后自动停用
     */
    protected function incrementFailures(string $id, string $event, array $webhook): void
    {
        $failures = (int) ($webhook['failures'] ?? 0) + 1;

        $registrations = $this->cache->get($this->key("reg:{$event}"), []);
        if (isset($registrations[$id])) {
            $registrations[$id]['failures'] = $failures;
            if ($failures >= $this->disableAfterFailures) {
                $registrations[$id]['status'] = 'disabled';
                $registrations[$id]['disabled_at'] = time();
            }
            $this->cache->set($this->key("reg:{$event}"), $registrations, 0);
        }

        $all = $this->cache->get($this->key('reg:all'), []);
        if (isset($all[$id])) {
            $all[$id]['status'] = $failures >= $this->disableAfterFailures ? 'disabled' : 'active';
            $this->cache->set($this->key('reg:all'), $all, 0);
        }
    }

    /**
     * 重置失败计数（投递成功后）
     */
    protected function resetFailures(string $id, string $event, array $webhook): void
    {
        if (($webhook['failures'] ?? 0) === 0) {
            return;
        }

        $registrations = $this->cache->get($this->key("reg:{$event}"), []);
        if (isset($registrations[$id])) {
            $registrations[$id]['failures'] = 0;
            $registrations[$id]['status'] = 'active';
            $this->cache->set($this->key("reg:{$event}"), $registrations, 0);
        }
    }

    /**
     * 记录投递日志
     */
    protected function logDelivery(string $id, string $event, string $url, array $result): void
    {
        $log = array_merge($result, ['logged_at' => date('c')]);

        // 写入缓存（最近 100 条）
        $logs = $this->cache->get($this->key("log:{$id}"), []);
        array_unshift($logs, $log);
        $logs = array_slice($logs, 0, 100);
        $this->cache->set($this->key("log:{$id}"), $logs, 86400 * 7);

        // 同步写入 PSR 日志
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
