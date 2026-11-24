<?php

declare(strict_types=1);

namespace wise\api\service;

use wise\api\dispatcher\LogDispatcher;
use wise\api\dto\ApiLog;

/**
 * 日志服务
 *
 * 门面级服务，供外部手动调用
 */
class LoggerService
{
    /** @var LogDispatcher 日志调度器 */
    private LogDispatcher $dispatcher;

    /**
     * @param LogDispatcher $dispatcher 日志调度器
     */
    public function __construct(LogDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * 记录一条 API 日志
     */
    public function log(ApiLog $log): void
    {
        $this->dispatcher->dispatch($log);
    }

    /**
     * 从数组数据创建并记录一条 API 日志
     *
     * @param array<string, mixed> $data 日志数据
     */
    public function logFromArray(array $data): void
    {
        $log = new ApiLog($data);
        $this->dispatcher->dispatch($log);
    }

    /**
     * 刷新所有驱动的缓冲区
     */
    public function flush(): void
    {
        $this->dispatcher->flush();
    }
}
