<?php

declare(strict_types=1);

namespace wise\api\driver;

use Psr\Log\LoggerInterface;
use think\db\ConnectionInterface;
use wise\api\contract\LogDriverInterface;
use wise\api\dto\ApiLog;

/**
 * 数据库日志驱动
 *
 * 缓冲批量写入，flush() 时批量 INSERT
 */
class DatabaseDriver implements LogDriverInterface
{
    /** @var ConnectionInterface 数据库连接 */
    private ConnectionInterface $connection;

    /** @var string 日志表名 */
    private string $table;

    /** @var int 批量写入大小 */
    private int $batchSize;

    /** @var LoggerInterface PSR-3 日志 */
    private LoggerInterface $logger;

    /** @var array<int, ApiLog> 缓冲区 */
    private array $buffer = [];

    /**
     * @param ConnectionInterface $connection 数据库连接
     * @param string $table 日志表名
     * @param int $batchSize 批量写入大小
     * @param LoggerInterface $logger PSR-3 日志
     */
    public function __construct(
        ConnectionInterface $connection,
        string $table,
        int $batchSize,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->table = $table;
        $this->batchSize = $batchSize;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function write(ApiLog $log): void
    {
        try {
            $this->buffer[] = $log;

            // 缓冲区达到 batch_size 时自动 flush
            if (count($this->buffer) >= $this->batchSize) {
                $this->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('[ApiLogger] DatabaseDriver write 失败: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * {@inheritdoc}
     *
     * 将缓冲区中的所有日志批量 INSERT 到数据库
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $rows = [];
            foreach ($this->buffer as $log) {
                $rows[] = $this->logToRow($log);
            }
            $this->connection->table($this->table)->insertAll($rows);
            $this->buffer = [];
        } catch (\Throwable $e) {
            $this->logger->error('[ApiLogger] DatabaseDriver flush 失败: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            // 清空缓冲区避免内存泄漏（即使写入失败也不重复写入旧数据）
            $this->buffer = [];
        }
    }

    /**
     * 检测并自动创建日志表
     *
     * 当 auto_create_table 配置为 true 时由 ServiceProvider 调用
     */
    public function checkAndAutoCreateTable(): void
    {
        try {
            // 检测表是否已存在
            $exists = $this->tableExists();
            if ($exists) {
                return;
            }

            $this->createTable();
            $this->logger->info('[ApiLogger] 自动创建日志表成功: ' . $this->table);
        } catch (\Throwable $e) {
            $this->logger->error('[ApiLogger] 自动创建日志表失败: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * 检测表是否存在
     */
    public function tableExists(): bool
    {
        try {
            $sql = "SELECT 1 FROM `{$this->table}` LIMIT 1";
            $this->connection->query($sql);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 执行建表 SQL
     */
    public function createTable(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$this->table}` (
    `log_id`           CHAR(36)        NOT NULL COMMENT '日志主键 UUID v4',
    `request_id`       VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '请求唯一标识',
    `app_key`          VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '应用标识',
    `duration_ms`      INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '接口耗时(毫秒)',
    `request_ip`       VARCHAR(45)     NOT NULL DEFAULT '' COMMENT '客户端IP',
    `request_method`   VARCHAR(10)     NOT NULL DEFAULT '' COMMENT 'HTTP方法',
    `request_url`      VARCHAR(2048)   NOT NULL DEFAULT '' COMMENT '完整请求URL',
    `request_route`    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'TP6路由标识',
    `request_headers`  JSON            NULL COMMENT '请求头JSON',
    `request_body`     TEXT            NULL COMMENT '请求体',
    `request_query`    JSON            NULL COMMENT 'URL查询参数JSON',
    `response_status`  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'HTTP响应状态码',
    `response_headers` JSON            NULL COMMENT '响应头JSON',
    `response_body`    TEXT            NULL COMMENT '响应体',
    `user_id`          VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '用户ID',
    `user_agent`       VARCHAR(512)    NOT NULL DEFAULT '' COMMENT 'User-Agent',
    `trace_id`         VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '分布式追踪ID',
    `channel`          VARCHAR(32)     NOT NULL DEFAULT '' COMMENT '日志通道',
    `extra`            JSON            NULL COMMENT '扩展字段JSON',
    `create_time`       DATETIME        NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`log_id`),
    INDEX `idx_request_id` (`request_id`),
    INDEX `idx_app_key` (`app_key`),
    INDEX `idx_create_time` (`create_time`),
    INDEX `idx_route_method` (`request_route`, `request_method`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_duration` (`duration_ms`),
    INDEX `idx_channel_created` (`channel`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API接口日志表';
SQL;
        $this->connection->execute($sql);
    }

    /**
     * 将 ApiLog DTO 转换为数据库行数据
     *
     * @return array<string, mixed>
     */
    private function logToRow(ApiLog $log): array
    {
        return [
            'log_id'           => $log->getLogId(),
            'request_id'       => $log->getRequestId(),
            'app_key'          => $log->getAppKey(),
            'duration_ms'      => $log->getDurationMs(),
            'request_ip'       => $log->getRequestIp(),
            'request_method'   => $log->getRequestMethod(),
            'request_url'      => $log->getRequestUrl(),
            'request_route'    => $log->getRequestRoute(),
            'request_headers'  => $log->getRequestHeaders() !== null
                ? json_encode($log->getRequestHeaders(), JSON_UNESCAPED_UNICODE) : null,
            'request_body'     => $log->getRequestBody(),
            'request_query'    => $log->getRequestQuery() !== null
                ? json_encode($log->getRequestQuery(), JSON_UNESCAPED_UNICODE) : null,
            'response_status'  => $log->getResponseStatus(),
            'response_headers' => $log->getResponseHeaders() !== null
                ? json_encode($log->getResponseHeaders(), JSON_UNESCAPED_UNICODE) : null,
            'response_body'    => $log->getResponseBody(),
            'user_id'          => $log->getUserId(),
            'user_agent'       => $log->getUserAgent(),
            'trace_id'         => $log->getTraceId(),
            'channel'          => $log->getChannel(),
            'extra'            => $log->getExtra() !== null
                ? json_encode($log->getExtra(), JSON_UNESCAPED_UNICODE) : null,
            'create_time'       => $log->getCreatedAt(),
        ];
    }
}
