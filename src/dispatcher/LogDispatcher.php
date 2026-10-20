<?php

declare(strict_types=1);

namespace wise\api\dispatcher;

use Psr\Log\LoggerInterface;
use wise\api\contract\LogDriverInterface;
use wise\api\dto\ApiLog;

/**
 * 日志调度器
 *
 * 持有多个 LogDriverInterface 实例，将日志分发给所有驱动
 * 单驱动异常不阻塞其他驱动（try-catch 隔离）
 */
class LogDispatcher
{
    /** @var array<int, LogDriverInterface> 已注册的驱动列表 */
    private array $drivers = [];

    /** @var LoggerInterface PSR-3 日志 */
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface $logger PSR-3 日志
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 添加驱动
     */
    public function addDriver(LogDriverInterface $driver): void
    {
        $this->drivers[] = $driver;
    }

    /**
     * 分发日志到所有驱动
     *
     * 对每个驱动的 write() 做 try-catch 隔离，确保单驱动异常不阻塞其他驱动
     */
    public function dispatch(ApiLog $log): void
    {
        foreach ($this->drivers as $driver) {
            try {
                $driver->write($log);
            } catch (\Throwable $e) {
                $this->logger->error('[ApiLogger] 驱动写入异常: ' . $e->getMessage(), [
                    'driver' => get_class($driver),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * 刷新所有驱动的缓冲区
     *
     * 对每个驱动的 flush() 做 try-catch 隔离
     */
    public function flush(): void
    {
        foreach ($this->drivers as $driver) {
            try {
                $driver->flush();
            } catch (\Throwable $e) {
                $this->logger->error('[ApiLogger] 驱动刷新异常: ' . $e->getMessage(), [
                    'driver' => get_class($driver),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * 获取已注册的驱动数量
     */
    public function getDriverCount(): int
    {
        return count($this->drivers);
    }

    /**
     * 获取已注册的驱动列表
     *
     * @return array<int, LogDriverInterface>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }
}
