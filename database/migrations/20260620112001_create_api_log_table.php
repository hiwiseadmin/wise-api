<?php

declare(strict_types=1);

/**
 * think-migration 迁移文件
 *
 * API 日志数据表迁移
 *
 * 默认表名 api_log，可通过 config('wise-api.table_names.api_log') 自定义。
 *
 * 使用方式：将此文件复制到项目的 database/migrations/ 目录下，
 * 然后执行 php think migrate:run
 */

use think\migration\Migrator;
use think\migration\db\Column;

class CreateApiLogTable extends Migrator
{
    /**
     * 获取表名（可通过 config('wise-api.table_names.api_log') 自定义）
     */
    protected function getTable(): string
    {
        return config('wise-api.table_names.api_log', 'api_log');
    }

    /**
     * 迁移版本号
     */
    public function up(): void
    {
        $tableName = $this->getTable();
        $table = $this->table($tableName, [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'API接口日志表',
            'id' => false,
            'primary_key' => ['log_id'],
        ]);

        $table
            ->addColumn(Column::char('log_id', 36)->setNull(false)->setComment('日志主键 UUID v4'))
            ->addColumn(Column::string('request_id', 64)->setNull(false)->setDefault('')->setComment('请求唯一标识'))
            ->addColumn(Column::string('app_key', 64)->setNull(false)->setDefault('')->setComment('应用标识'))
            ->addColumn(Column::integer('duration_ms')->setUnsigned()->setNull(false)->setDefault(0)->setComment('接口耗时(毫秒)'))
            ->addColumn(Column::string('request_ip', 45)->setNull(false)->setDefault('')->setComment('客户端IP'))
            ->addColumn(Column::string('request_method', 10)->setNull(false)->setDefault('')->setComment('HTTP方法'))
            ->addColumn(Column::string('request_url', 2048)->setNull(false)->setDefault('')->setComment('完整请求URL'))
            ->addColumn(Column::string('request_route', 255)->setNull(false)->setDefault('')->setComment('TP6路由标识'))
            ->addColumn(Column::text('request_headers')->setNull(true)->setComment('请求头（JSON）'))
            ->addColumn(Column::text('request_body')->setNull(true)->setComment('请求体'))
            ->addColumn(Column::text('request_query')->setNull(true)->setComment('URL查询参数（JSON）'))
            ->addColumn(Column::smallInteger('response_status')->setUnsigned()->setNull(false)->setDefault(0)->setComment('HTTP响应状态码'))
            ->addColumn(Column::text('response_headers')->setNull(true)->setComment('响应头（JSON）'))
            ->addColumn(Column::text('response_body')->setNull(true)->setComment('响应体'))
            ->addColumn(Column::string('user_id', 64)->setNull(false)->setDefault('')->setComment('用户ID'))
            ->addColumn(Column::string('user_agent', 512)->setNull(false)->setDefault('')->setComment('User-Agent'))
            ->addColumn(Column::string('trace_id', 64)->setNull(false)->setDefault('')->setComment('分布式追踪ID'))
            ->addColumn(Column::string('channel', 32)->setNull(false)->setDefault('')->setComment('日志通道'))
            ->addColumn(Column::text('extra')->setNull(true)->setComment('扩展字段（JSON）'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addIndex(['request_id'], ['name' => 'idx_request_id'])
            ->addIndex(['app_key'], ['name' => 'idx_app_key'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->addIndex(['request_route', 'request_method'], ['name' => 'idx_route_method'])
            ->addIndex(['user_id'], ['name' => 'idx_user_id'])
            ->addIndex(['duration_ms'], ['name' => 'idx_duration'])
            ->addIndex(['channel', 'create_time'], ['name' => 'idx_channel_created'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop();
        }
    }
}
