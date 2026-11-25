<?php

declare(strict_types=1);

namespace wise\api\contract;

use wise\api\dto\ApiLog;

/**
 * 日志驱动接口
 *
 * 所有日志驱动必须实现此接口
 */
interface LogDriverInterface
{
    /**
     * 写入一条日志
     *
     * @param ApiLog $log API日志数据
     */
    public function write(ApiLog $log): void;

    /**
     * 刷新缓冲区
     *
     * 将缓冲中的日志数据持久化到存储介质
     */
    public function flush(): void;
}
