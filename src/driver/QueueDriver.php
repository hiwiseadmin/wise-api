<?php

declare(strict_types=1);

namespace wise\api\driver;

use Psr\Log\LoggerInterface;
use wise\api\contract\LogDriverInterface;
use wise\api\dto\ApiLog;

/**
 * 队列日志驱动
 *
 * 将日志数据投递到 think-queue，异步处理
 */
class QueueDriver implements LogDriverInterface
{
    /** @var string 默认队列任务类名 */
    public const DEFAULT_JOB_CLASS = \wise\api\job\ApiLogJob::class;

    /** @var string 队列名称 */
    private string $queueName;

    /** @var string 队列任务类名 */
    private string $jobClass;

    /** @var int 延迟时间（秒） */
    private int $delay;

    /** @var LoggerInterface PSR-3 日志 */
    private LoggerInterface $logger;

    /**
     * @param string $queueName 队列名称
     * @param string $jobClass 队列任务类名
     * @param int $delay 延迟时间（秒）
     * @param LoggerInterface $logger PSR-3 日志
     */
    public function __construct(string $queueName, string $jobClass, int $delay, LoggerInterface $logger)
    {
        $this->queueName = $queueName;
        $this->jobClass = $jobClass;
        $this->delay = $delay;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     *
     * 将 ApiLog 数据序列化后投递到 think-queue
     */
    public function write(ApiLog $log): void
    {
        try {
            $data = $log->toArray();

            // 使用 think-queue 的 Queue facade 投递任务
            $this->pushToQueue($data);
        } catch (\Throwable $e) {
            $this->logger->error('[ApiLogger] QueueDriver 投递失败: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * {@inheritdoc}
     *
     * 队列驱动无需 flush，每次 write 已直接投递
     */
    public function flush(): void
    {
        // no-op: 队列驱动无需刷新缓冲区
    }

    /**
     * 投递到 think-queue
     *
     * @param array<string, mixed> $data 日志数据
     */
    private function pushToQueue(array $data): void
    {
        // 使用 think-queue 的 Queue facade 投递
        if (class_exists(\think\facade\Queue::class)) {
            $jobClass = !empty($this->jobClass) && class_exists($this->jobClass)
                ? $this->jobClass
                : '';

            // 未配置有效的 job_class 时，回退到包内默认 Job
            if (empty($jobClass)) {
                if (class_exists(self::DEFAULT_JOB_CLASS)) {
                    $jobClass = self::DEFAULT_JOB_CLASS;
                } else {
                    $this->logger->warning('[ApiLogger] QueueDriver: 未配置有效的 job_class 且默认 Job 不可用，跳过投递');
                    return;
                }
            }

            if ($this->delay > 0) {
                // 延迟投递
                \think\facade\Queue::later(
                    $this->delay,
                    $jobClass,
                    $data,
                    $this->queueName
                );
            } else {
                // 立即投递
                \think\facade\Queue::push(
                    $jobClass,
                    $data,
                    $this->queueName
                );
            }
        } elseif (function_exists('queue_push')) {
            // 通过 queue_push 函数投递
            $jobClass = !empty($this->jobClass) && class_exists($this->jobClass)
                ? $this->jobClass
                : '';

            // 未配置有效的 job_class 时，回退到包内默认 Job
            if (empty($jobClass)) {
                if (class_exists(self::DEFAULT_JOB_CLASS)) {
                    $jobClass = self::DEFAULT_JOB_CLASS;
                } else {
                    $this->logger->warning('[ApiLogger] QueueDriver: 未配置有效的 job_class 且默认 Job 不可用，跳过投递');
                    return;
                }
            }

            queue_push($jobClass, $data, $this->queueName, $this->delay);
        } else {
            $this->logger->warning('[ApiLogger] QueueDriver: 未检测到 think-queue，请安装 topthink/think-queue');
        }
    }
}
