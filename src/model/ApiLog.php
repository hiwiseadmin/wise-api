<?php

declare(strict_types=1);

namespace wise\api\model;

use think\Model;

/**
 * API 日志模型
 *
 * 对应 wise-api 日志落库时写入的 api_log 表（迁移文件见 database/migrations）。
 * 表的读写全部由 wise-api 自身负责（ApiLogMiddleware → DatabaseDriver），
 * 宿主项目（后台「API 日志」页面、日志分析服务）通过本模型只读/清理即可。
 *
 * 表名与连接取自 wise-api 配置，避免宿主改了
 * logger.drivers.database.table / connection 后产生错位：
 *   wise-api.logger.drivers.database.table     默认 api_log
 *   wise-api.logger.drivers.database.connection 默认空（使用默认连接）
 *
 * @package wise\api\model
 */
class ApiLog extends Model
{
    /**
     * 主键：日志 UUID
     * @var string
     */
    protected $pk = 'log_id';

    /**
     * 由 DatabaseDriver 显式写入 create_time，模型不接管时间戳
     * @var bool
     */
    protected $autoWriteTimestamp = false;

    public function __construct(array | object $data = [])
    {
        $table = (string) config('wise-api.logger.drivers.database.table', 'api_log');
        if ($table !== '') {
            // 配置项是逻辑表名（默认 api_log），必须走 name 让 think-orm 按连接前缀补全。
            // 直接赋值 $this->table 会绕过前缀（Model::db() 用 table() 覆盖 name），
            // 在 db_prefix=ws_ 的部署里查询 wise.api_log → 表不存在 → 后台「API 日志」500。
            $this->name = $table;
        }

        $connection = (string) config('wise-api.logger.drivers.database.connection', '');
        if ($connection !== '') {
            $this->connection = $connection;
        }

        parent::__construct($data);
    }
}
