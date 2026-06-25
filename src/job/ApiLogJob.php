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
            // 从容器获取数据库配置
            $dbConfig = app()->config->get('wise-api.logger.drivers.database', []);
            $tableName = app()->config->get('wise-api.table_names.api_log', 'api_log');
            $connectionName = $dbConfig['connection'] ?? '';

            // 获取数据库连接
            $connection = !empty($connectionName)
                ? app()->db->connection($connectionName)
                : app()->db->connection();

            // 数组字段 JSON 序列化：数据库 JSON 列需要字符串，ThinkPHP 不会自动转换
            $jsonFields = ['request_headers', 'request_query', 'response_headers', 'extra'];
            foreach ($jsonFields as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
                }
            }

            // 将日志数据插入数据库表
            $connection->table($tableName)->insert($data);

            // 执行成功，删除任务
            $job->delete();
        } catch (\Throwable $e) {
            // 执行失败，记录错误日志，不删除任务以触发 think-queue 自动重试
            trace(
                '[ApiLogger] ApiLogJob 执行失败: ' . $e->getMessage(),
                'error'
            );

            // 最多执行3次（含首次），超过后放弃重试
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
            // 日志记录自身失败时静默处理，避免抛出异常
        }
    }
}
