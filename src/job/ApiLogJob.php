<?php

declare(strict_types=1);

namespace wise\api\job;

use think\queue\Job;

/**
 * 默认 API 日志队列 Job
 *
 * 接收队列数据后写入数据库，实现开箱即用的异步写库场景。
 * 由 think-queue 实例化，因此通过 ThinkPHP 容器（app()）获取数据库连接和配置，
 * 而非构造函数注入。
 */
class ApiLogJob
{
    /**
     * 执行队列任务：将日志数据写入数据库
     *
     * @param Job $job 队列任务实例
     * @param array<string, mixed> $data 日志数据（ApiLog::toArray() 的返回值）
     */
    public function fire(Job $job, $data): void
    {
        try {
            $dbConfig = app()->config->get('wise-api.logger.drivers.database', []);
            $tableName = 'api_log';
            $connectionName = $dbConfig['connection'] ?? '';

            $connection = !empty($connectionName)
                ? app()->db->connection($connectionName)
                : app()->db->connection();

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid table name: %s', $tableName)
                );
            }

            $jsonFields = ['request_headers', 'request_query', 'response_headers', 'extra'];
            foreach ($jsonFields as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
                }
            }

            $connection->table($tableName)->insert($data);

            $job->delete();
        } catch (\Throwable $e) {
            trace(
                '[ApiLogger] ApiLogJob 执行失败: ' . $e->getMessage(),
                'error'
            );

            if ($job->attempts() >= 3) {
                $this->failed($data);
                $job->delete();
            }
        }
    }

    /**
     * 任务最终失败回调
     *
     * 当任务重试次数耗尽后触发，记录错误日志供排查
     *
     * @param array<string, mixed> $data 日志数据
     */
    public function failed($data): void
    {
        try {
            trace(
                '[ApiLogger] ApiLogJob 任务最终失败，日志数据: ' . json_encode($data, JSON_UNESCAPED_UNICODE),
                'error'
            );
        } catch (\Throwable $e) {
        }
    }
}
