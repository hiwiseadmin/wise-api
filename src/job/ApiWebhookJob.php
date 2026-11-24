<?php

declare(strict_types=1);

namespace wise\api\job;

use think\queue\Job;
use wise\support\backoff\BackoffStrategy;
use wise\webhook\server\WebhookCall;

/**
 * API Webhook 异步投递 Job
 *
 * 为什么需要它：同步投递（WebhookCall ... dispatchSync）在生产路径上最坏
 * 3 次重试 × (5s 连接 + 10s 读) + 1s/3s/9s 退避 ≈ 58s 阻塞当前请求。
 * FPM 下表现为 504，Swoole 下会把整个 worker 的所有协程一起拖死。
 *
 * 本 Job 由 think-queue 实例化，因此通过容器（app()）获取依赖，
 * 不使用构造函数注入（与 ApiLogJob 保持一致）。
 *
 * @package wise\api\job
 */
class ApiWebhookJob
{
    /**
     * 执行队列任务：向单个 webhook 投递
     *
     * @param Job                  $job  队列任务实例
     * @param array<string, mixed> $data 投递参数（由 WebhookManager::deliver 入队）
     * @return void
     */
    public function fire(Job $job, $data): void
    {
        if (!is_array($data) || empty($data['url'])) {
            $job->delete();

            return;
        }

        try {
            $result = WebhookCall::create()
                ->url((string) $data['url'])
                ->payload([
                    'event'   => (string) ($data['event'] ?? ''),
                    'payload' => (array) ($data['payload'] ?? []),
                    'id'      => (string) ($data['id'] ?? ''),
                    'ts'      => time(),
                ])
                ->useSecret((string) ($data['secret'] ?? ''))
                ->timeout((int) ($data['timeout'] ?? 10))
                ->connectTimeout((int) ($data['connectTimeout'] ?? 5))
                ->maxRetries((int) ($data['maxRetries'] ?? 3))
                ->backoffStrategy(BackoffStrategy::exponential(1000, 9000))
                ->dispatchSync();

            $job->delete();

            $this->reportResult($data, (bool) ($result['success'] ?? false));
        } catch (\Throwable $e) {
            trace('[wise-api] ApiWebhookJob 执行失败: ' . $e->getMessage(), 'error');

            if ($job->attempts() >= 3) {
                $this->failed($data);
                $job->delete();
            }
        }
    }

    /**
     * 任务最终失败回调
     *
     * @param array<string, mixed> $data 投递参数
     * @return void
     */
    public function failed($data): void
    {
        try {
            trace(
                '[wise-api] ApiWebhookJob 最终失败: ' . json_encode($data, JSON_UNESCAPED_UNICODE),
                'error'
            );
        } catch (\Throwable) {
        }
    }

    /**
     * 把投递结果上报给 WebhookManager
     *
     * 上报失败不影响任务本身（任务已投递完成）。
     *
     * @param array<string, mixed> $data    投递参数
     * @param bool                 $success 是否成功
     * @return void
     */
    protected function reportResult(array $data, bool $success): void
    {
        try {
            app(\wise\api\webhook\WebhookManager::class)->reportDeliveryResult(
                (string) ($data['id'] ?? ''),
                (string) ($data['event'] ?? ''),
                $success
            );
        } catch (\Throwable) {
        }
    }
}
