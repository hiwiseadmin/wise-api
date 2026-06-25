<?php
declare(strict_types=1);

namespace wise\api\circuit;

use think\Cache;

/**
 * 断路器（Circuit Breaker）
 *
 * 保护系统免受外部依赖故障的级联影响。
 *
 * 三态转换：
 *   CLOSED → (连续失败 N 次) → OPEN
 *   OPEN   → (冷却 T 秒后)    → HALF_OPEN
 *   HALF_OPEN → (探测成功)    → CLOSED
 *   HALF_OPEN → (探测失败)    → OPEN
 *
 * 状态存储在 Redis 中，支持分布式部署。
 *
 * 用法：
 *   $cb = new CircuitBreaker('payment_service', cache('redis'), [
 *       'failure_threshold' => 5,
 *       'timeout'           => 30,
 *   ]);
 *
 *   if (!$cb->isAvailable()) {
 *       return ['error' => 'Service unavailable'];
 *   }
 *
 *   try {
 *       $result = callExternalService();
 *       $cb->reportSuccess();
 *       return $result;
 *   } catch (\Exception $e) {
 *       $cb->reportFailure();
 *       throw $e;
 *   }
 *
 * @package wise\api\circuit
 */
class CircuitBreaker
{
    /** @var string 状态：关闭（正常） */
    public const STATE_CLOSED = 'closed';

    /** @var string 状态：打开（熔断） */
    public const STATE_OPEN = 'open';

    /** @var string 状态：半开（探测） */
    public const STATE_HALF_OPEN = 'half_open';

    /** @var int 熔断阈值 */
    protected int $failureThreshold;

    /** @var int 冷却时间（秒） */
    protected int $timeout;

    /** @var int 半开最大探测请求数 */
    protected int $halfOpenMaxRequests;

    /** @var int 半开成功阈值 */
    protected int $successThreshold;

    /**
     * @param string        $serviceName 服务标识
     * @param Cache|object  $cache       缓存实例（Redis）
     * @param array         $options      选项
     *   - failure_threshold: 连续失败多少次后熔断（默认 5）
     *   - timeout: 熔断后冷却时间/秒（默认 30）
     *   - half_open_max_requests: 半开状态允许的探测请求数（默认 3）
     *   - success_threshold: 半开状态连续成功多少次后关闭（默认 2）
     */
    public function __construct(
        protected string $serviceName,
        protected $cache,
        protected array $options = []
    ) {
        $this->failureThreshold    = (int) ($options['failure_threshold'] ?? 5);
        $this->timeout             = (int) ($options['timeout'] ?? 30);
        $this->halfOpenMaxRequests = (int) ($options['half_open_max_requests'] ?? 3);
        $this->successThreshold    = (int) ($options['success_threshold'] ?? 2);
    }

    /**
     * 检查服务是否可用
     *
     * CLOSED 状态 → 始终返回 true
     * OPEN 状态 → 检查是否已过冷却时间
     * HALF_OPEN → 检查探测请求数是否超限
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $openedAt = (int) $this->cache->get($this->key('opened_at'), 0);
            if (time() - $openedAt >= $this->timeout) {
                // 冷却时间到，转为半开
                $this->transitionTo(self::STATE_HALF_OPEN);
                return true;
            }
            return false;
        }

        // HALF_OPEN：限制探测请求数
        $halfOpenRequests = (int) $this->cache->get($this->key('half_open_count'), 0);
        return $halfOpenRequests < $this->halfOpenMaxRequests;
    }

    /**
     * 上报成功（应在每个成功调用后调用）
     */
    public function reportSuccess(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            // CLOSED 下成功 → 重置失败计数
            $this->cache->set($this->key('failures'), 0, 3600);
            return;
        }

        if ($state === self::STATE_HALF_OPEN) {
            // HALF_OPEN 下成功 → 递增成功计数
            $successes = (int) $this->cache->get($this->key('half_open_successes'), 0) + 1;
            $this->cache->set($this->key('half_open_successes'), $successes, 3600);

            if ($successes >= $this->successThreshold) {
                $this->transitionTo(self::STATE_CLOSED);
            }
        }
    }

    /**
     * 上报失败（应在每个失败调用后调用）
     */
    public function reportFailure(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            $failures = (int) $this->cache->get($this->key('failures'), 0) + 1;
            $this->cache->set($this->key('failures'), $failures, 3600);

            if ($failures >= $this->failureThreshold) {
                $this->transitionTo(self::STATE_OPEN);
            }
            return;
        }

        if ($state === self::STATE_HALF_OPEN) {
            // HALF_OPEN 下失败 → 立即重新打开
            $this->transitionTo(self::STATE_OPEN);
        }
    }

    /**
     * 获取当前状态
     */
    public function getState(): string
    {
        $v = $this->cache->get($this->key('state'), self::STATE_CLOSED);
        return is_string($v) ? $v : self::STATE_CLOSED;
    }

    /**
     * 获取失败次数
     */
    public function getFailureCount(): int
    {
        return (int) $this->cache->get($this->key('failures'), 0);
    }

    /**
     * 重置断路器（手动恢复）
     */
    public function reset(): void
    {
        $this->transitionTo(self::STATE_CLOSED);
    }

    /**
     * 状态迁移（带 RabbitMQ/事件钩子预留）
     */
    protected function transitionTo(string $newState): void
    {
        $oldState = $this->getState();
        if ($oldState === $newState && $newState !== self::STATE_OPEN) {
            return;
        }

        // 记录新状态
        $this->cache->set($this->key('state'), $newState, 86400);

        // OPEN：记录打开时间 + 重置失败计数
        if ($newState === self::STATE_OPEN) {
            $this->cache->set($this->key('opened_at'), time(), 86400);
            $this->cache->set($this->key('failures'), 0, 3600);
        }

        // HALF_OPEN：重置探测计数
        if ($newState === self::STATE_HALF_OPEN) {
            $this->cache->set($this->key('half_open_count'), 0, 3600);
            $this->cache->set($this->key('half_open_successes'), 0, 3600);
        }

        // CLOSED：清理所有状态
        if ($newState === self::STATE_CLOSED) {
            $this->cache->set($this->key('failures'), 0, 3600);
            $this->cache->set($this->key('half_open_count'), 0, 3600);
            $this->cache->set($this->key('half_open_successes'), 0, 3600);
        }

        // 递增半开探测计数（如果是半开状态被触发）
        if ($newState === self::STATE_HALF_OPEN || $newState === self::STATE_CLOSED) {
            $this->cache->set(
                $this->key('half_open_count'),
                (int) $this->cache->get($this->key('half_open_count'), 0) + 1,
                3600
            );
        }

        // @hook 可在此触发事件/通知：CircuitBreakerStateChanged($this->serviceName, $oldState, $newState)
    }

    /**
     * 生成缓存键前缀
     */
    private function key(string $suffix): string
    {
        return "cb:{$this->serviceName}:{$suffix}";
    }
}
